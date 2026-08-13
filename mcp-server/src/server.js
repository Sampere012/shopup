import express from 'express';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';
import Groq from 'groq-sdk';

dotenv.config();

const app = express();
const port = Number(process.env.PORT || 3001);
const dbPrefix = String(process.env.DB_TABLE_PREFIX || 'wp_');
const businessTable = `${dbPrefix}ws_businesses`;

app.use(express.json({ limit: '2mb' }));

const ensurePool = () => {
  if (!globalThis.__workshopDbPool) {
    globalThis.__workshopDbPool = mysql.createPool({
      host: process.env.DB_HOST || 'localhost',
      port: Number(process.env.DB_PORT || 3306),
      user: process.env.DB_USER || 'root',
      password: process.env.DB_PASSWORD || '',
      database: process.env.DB_NAME || 'workshop',
      waitForConnections: true,
      connectionLimit: 10,
      queueLimit: 0,
      charset: 'utf8mb4',
    });
  }
  return globalThis.__workshopDbPool;
};

const tableName = (name, businessSlug = '') => {
  const normalized = String(businessSlug || '').trim().toLowerCase().replace(/[^a-z0-9-_]+/g, '-').replace(/^-+|-+$/g, '');
  if (!normalized) {
    return `${dbPrefix}ws_${name}`;
  }
  return `${dbPrefix}ws_${normalized}_ws_${name}`;
};

const stripObject = (value) => {
  if (!value || typeof value !== 'object') {
    return value;
  }
  const cleaned = { ...value };
  Object.keys(cleaned).forEach((key) => {
    if (cleaned[key] === undefined) {
      delete cleaned[key];
    }
  });
  return cleaned;
};

const queryScalar = async (sql, params = []) => {
  const pool = ensurePool();
  const [rows] = await pool.query(sql, params);
  if (!rows || rows.length === 0) {
    return 0;
  }
  const first = rows[0];
  const value = Object.values(first)[0];
  return Number(value || 0);
};

const tableExists = async (table) => {
  const pool = ensurePool();
  const [rows] = await pool.query(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
    [table]
  );
  return Array.isArray(rows) && rows.length > 0;
};

const fetchBusinessRow = async ({ businessSlug, businessId } = {}) => {
  const pool = ensurePool();
  const where = [];
  const params = [];

  if (businessId) {
    where.push('id = ?');
    params.push(Number(businessId));
  }

  if (businessSlug) {
    where.push('slug = ?');
    params.push(String(businessSlug).trim());
  }

  if (!where.length) {
    return { id: 0, slug: '', name: 'Default', is_default: true };
  }

  const [rows] = await pool.query(
    `SELECT * FROM ${businessTable} WHERE ${where.join(' OR ')} ORDER BY id DESC LIMIT 1`,
    params
  );

  if (!rows || rows.length === 0) {
    return null;
  }

  return stripObject(rows[0]);
};

const listBusinesses = async () => {
  const pool = ensurePool();
  const [rows] = await pool.query(`SELECT id, slug, name, status, created_at FROM ${businessTable} ORDER BY name ASC`);
  return Array.isArray(rows) ? rows : [];
};

const getBusinessSummary = async ({ businessSlug, businessId } = {}) => {
  const business = (await fetchBusinessRow({ businessSlug, businessId })) || {
    id: 0,
    slug: '',
    name: 'Default',
    is_default: true,
  };

  const tables = {
    products: tableName('products', business.slug),
    categories: tableName('categories', business.slug),
    customers: tableName('customers', business.slug),
    stock: tableName('stock', business.slug),
    pos_sales: tableName('pos_sales', business.slug),
    expenses: tableName('expenses', business.slug),
  };

  const stats = {
    business,
    tables,
    products: 0,
    categories: 0,
    customers: 0,
    low_stock: 0,
    out_of_stock: 0,
    sales_month: 0,
    revenue_month: 0,
    expenses_month: 0,
    pending_orders: 0,
  };

  for (const [key, name] of Object.entries(tables)) {
    if (await tableExists(name)) {
      switch (key) {
        case 'products':
          stats.products = await queryScalar(`SELECT COUNT(*) FROM ${name}`);
          break;
        case 'categories':
          stats.categories = await queryScalar(`SELECT COUNT(*) FROM ${name}`);
          break;
        case 'customers':
          stats.customers = await queryScalar(`SELECT COUNT(*) FROM ${name}`);
          break;
        case 'stock':
          stats.low_stock = await queryScalar(`SELECT COUNT(*) FROM ${name} WHERE qty > 0 AND qty <= min_stock`);
          stats.out_of_stock = await queryScalar(`SELECT COUNT(*) FROM ${name} WHERE qty <= 0`);
          break;
        case 'pos_sales':
          stats.sales_month = await queryScalar(
            `SELECT COUNT(*) FROM ${name} WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)`
          );
          stats.revenue_month = Number(
            (await queryScalar(
              `SELECT COALESCE(SUM(total),0) FROM ${name} WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)`
            )) || 0
          );
          stats.pending_orders = await queryScalar(`SELECT COUNT(*) FROM ${name} WHERE status = 'pending'`);
          break;
        case 'expenses':
          stats.expenses_month = Number(
            (await queryScalar(
              `SELECT COALESCE(SUM(amount),0) FROM ${name} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)`
            )) || 0
          );
          break;
        default:
          break;
      }
    }
  }

  return stats;
};

const buildBusinessPrompt = (summary) => {
  const b = summary.business || {};
  return [
    `Negocio: ${b.name || 'Sin nombre'}`,
    `Slug: ${b.slug || 'default'}`,
    `Productos: ${summary.products}`,
    `Categorías: ${summary.categories}`,
    `Clientes: ${summary.customers}`,
    `Pedidos pendientes: ${summary.pending_orders}`,
    `Ventas en 30 días: ${summary.sales_month}`,
    `Ingresos en 30 días: ${Number(summary.revenue_month || 0).toFixed(2)}`,
    `Gastos en 30 días: ${Number(summary.expenses_month || 0).toFixed(2)}`,
    `Stock bajo: ${summary.low_stock}`,
    `Agotados: ${summary.out_of_stock}`,
  ].join('\n');
};

const askGroq = async ({ question, businessSlug, businessId }) => {
  const apiKey = process.env.GROQ_API_KEY;
  if (!apiKey) {
    throw new Error('GROQ_API_KEY is not configured.');
  }

  const summary = await getBusinessSummary({ businessSlug, businessId });
  const groq = new Groq({ apiKey });
  const model = process.env.GROQ_MODEL || 'llama-3.3-70b-versatile';

  const completion = await groq.chat.completions.create({
    model,
    temperature: 0.2,
    messages: [
      {
        role: 'system',
        content:
          'Eres un asistente experto para una plataforma de negocio. Responde con base en los datos reales del negocio del usuario. Si faltan datos, dilo con honestidad y sugiere qué revisar.',
      },
      {
        role: 'user',
        content: `Pregunta del usuario: ${question}\n\nContexto del negocio:\n${buildBusinessPrompt(summary)}`,
      },
    ],
  });

  return {
    answer: completion?.choices?.[0]?.message?.content || 'No se obtuvo respuesta.',
    business: summary.business,
    summary,
  };
};

const toolCatalog = [
  {
    name: 'list_businesses',
    description: 'Lista todos los negocios disponibles en la base de datos.',
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  },
  {
    name: 'get_business_summary',
    description: 'Devuelve los indicadores clave de un negocio concreto.',
    inputSchema: {
      type: 'object',
      properties: {
        businessSlug: { type: 'string' },
        businessId: { type: 'number' },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'ask_business_question',
    description: 'Hace una pregunta en lenguaje natural usando el contexto real del negocio y Groq.',
    inputSchema: {
      type: 'object',
      properties: {
        businessSlug: { type: 'string' },
        businessId: { type: 'number' },
        question: { type: 'string' },
      },
      required: ['question'],
      additionalProperties: false,
    },
  },
];

const executeTool = async (tool, args = {}) => {
  switch (tool) {
    case 'list_businesses':
      return { ok: true, data: await listBusinesses() };
    case 'get_business_summary':
      return { ok: true, data: await getBusinessSummary(args) };
    case 'ask_business_question': {
      const { question } = args;
      if (!question || !String(question).trim()) {
        return { ok: false, error: 'La pregunta es obligatoria.' };
      }
      const result = await askGroq(args);
      return { ok: true, data: result };
    }
    default:
      return { ok: false, error: `Tool not found: ${tool}` };
  }
};

app.get('/health', (_req, res) => {
  res.json({ ok: true, status: 'healthy', service: 'workshop-mcp-server', version: '1.0.0' });
});

app.get('/mcp/tools', (_req, res) => {
  res.json({ ok: true, tools: toolCatalog });
});

app.post('/mcp', async (req, res) => {
  try {
    const body = req.body || {};

    if (body.jsonrpc === '2.0') {
      const { method, params = {} } = body;
      if (method === 'tools/list') {
        return res.json({ jsonrpc: '2.0', result: { tools: toolCatalog } });
      }
      if (method === 'tools/call') {
        const tool = params?.name || params?.tool;
        const args = params?.arguments || params?.args || {};
        const result = await executeTool(tool, args);
        return res.json({ jsonrpc: '2.0', result });
      }
      return res.status(400).json({ jsonrpc: '2.0', error: { code: -32601, message: `Unknown method: ${method}` } });
    }

    const tool = body.tool || body.name;
    const args = body.args || body.arguments || {};
    const result = await executeTool(tool, args);
    return res.json(result);
  } catch (error) {
    console.error('MCP error:', error);
    return res.status(500).json({ ok: false, error: error.message || 'Unexpected server error' });
  }
});

app.post('/mcp/call', async (req, res) => {
  try {
    const { tool, args = {} } = req.body || {};
    const result = await executeTool(tool, args);
    return res.json(result);
  } catch (error) {
    console.error('Call error:', error);
    return res.status(500).json({ ok: false, error: error.message || 'Unexpected server error' });
  }
});

app.post('/query', async (req, res) => {
  try {
    const { businessSlug, businessId, question } = req.body || {};
    if (!question || !String(question).trim()) {
      return res.status(400).json({ ok: false, error: 'question is required.' });
    }

    const result = await askGroq({ businessSlug, businessId, question });
    return res.json({ ok: true, data: result });
  } catch (error) {
    console.error('Query error:', error);
    return res.status(500).json({ ok: false, error: error.message || 'Unexpected server error' });
  }
});

app.use((err, _req, res, _next) => {
  console.error('Unhandled error:', err);
  res.status(500).json({ ok: false, error: err.message || 'Server error' });
});

app.listen(port, () => {
  console.log(`Workshop MCP Server v1 listening on http://localhost:${port}`);
});
