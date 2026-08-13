# Workshop MCP Server v1

This is the first version of the standalone MCP-style server for the Workshop marketplace.
It connects to the WordPress database and exposes business-aware tools that can be used by an AI client or by a Groq-powered assistant.

## Features

- Lists businesses from the WordPress database
- Resolves the active business by slug or id
- Reads product, category, sales, customer and expense data from the correct business tables
- Exposes MCP-style tool endpoints for AI clients
- Sends a Groq-generated answer grounded in the current business context

## Quick start

1. Copy `.env.example` to `.env`
2. Fill in your database and Groq credentials
3. Install dependencies:

```bash
npm install
```

4. Start the server:

```bash
npm start
```

## Endpoints

- `GET /health` — health check
- `GET /mcp/tools` — list available tools
- `POST /mcp` — tool execution endpoint
- `POST /mcp/call` — convenience endpoint for AI calls
- `POST /query` — ask a natural-language question about the current business context

## Example request

```bash
curl -X POST http://localhost:3001/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "get_business_summary",
    "args": { "businessSlug": "mi-negocio" }
  }'
```

## Example question

```bash
curl -X POST http://localhost:3001/query \
  -H "Content-Type: application/json" \
  -d '{
    "businessSlug": "mi-negocio",
    "question": "¿Cuánto está vendiendo esta tienda este mes y qué productos necesitan atención?"
  }'
```

## Notes

This is a v1 implementation intended to be simple, reliable and easy to extend. It is designed around the actual Workshop schema, including the per-business table pattern `wp_ws_{slug}_ws_*`.
