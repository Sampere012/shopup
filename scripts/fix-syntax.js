// Fix the broken test file by fixing the syntax issues
const fs = require('fs');
const file = 'tests/e2e/mobile-app.spec.js';
let c = fs.readFileSync(file, 'utf8');

// Fix the broken test 1.7 - remove duplicate lines
c = c.replace(
  `    }
  });
    expect(creds.length).toBeGreaterThanOrEqual(1);
    expect(creds[0].user).toBe('admin@test.com');
  });

  test('1.8`,
  `    }
  });

  test('1.8`
);

// Check for any remaining syntax issues
// Look for unmatched braces
let openBraces = 0;
let closeBraces = 0;
for (const ch of c) {
  if (ch === '{') openBraces++;
  if (ch === '}') closeBraces++;
}
console.log('Open braces:', openBraces, 'Close braces:', closeBraces);

// Also check for the section markers to make sure they're intact
const sections = [
  '1. Login', '2. Navegación', '3. Conexión', '4. Modo offline',
  '5. Cola de pendientes', '6. Funcionalidad', '7. Bloqueo',
  '8. Base de datos', '9. Sincronización', '10. Cerrar sesión',
  '11. Múltiples usuarios', '12. Flujo completo', '13. Resistencia'
];
for (const s of sections) {
  if (!c.includes(s)) console.log('MISSING section:', s);
}

fs.writeFileSync(file, c, 'utf8');
console.log('Fixed. File size:', c.length);
