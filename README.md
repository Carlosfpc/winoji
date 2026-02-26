# Winoji

Herramienta de colaboración para equipos — wiki estilo Notion + issues estilo Jira + integración con GitHub.

## Funcionalidades

- **Wiki** — páginas con editor de texto enriquecido, historial de versiones, estructura anidada y drag & drop
- **Issues** — tablero kanban, prioridades, etiquetas, asignados, comentarios, checklist, dependencias, templates, búsqueda y exportación CSV
- **GitHub** — conecta repositorios, crea ramas desde issues, gestiona PRs y visualiza diffs
- **SonarQube** — integración con análisis de calidad de código (bugs, vulnerabilidades, ratings, deuda técnica)
- **Roadmap** — vista de calendario mensual con issues por fecha de entrega
- **Sprints** — planificación de sprints con issues asignadas
- **Notificaciones** — notificaciones en tiempo real dentro de la app y por email
- **Equipo** — invita miembros con notificación por email, roles (admin / manager / employee)
- **Dashboard** — estadísticas de issues y actividad reciente
- **Perfil** — gestión de cuenta, contraseña, avatar y token de GitHub

## Stack técnico

- PHP 8.x (sin framework, sin composer)
- MySQL 8.x
- Vanilla JS
- Apache / Nginx

## Instalación (Apache/Nginx)

### Requisitos

- PHP 8.x con extensiones: `pdo_mysql`, `openssl`
- MySQL 8.x
- Apache con `mod_rewrite` habilitado, o Nginx

### Pasos

```bash
# 1. Configurar entorno
cp .env.example .env
# Editar .env: credenciales DB, ENCRYPT_KEY, APP_URL, SMTP

# 2. Crear base de datos
mysql -u root -p -e "CREATE DATABASE teamapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Cargar schema y migraciones
mysql -u root -p teamapp < db/schema.sql
php db/migrate.php

# 4. Apuntar el document root al directorio public/
```

### Virtual Host Apache

```apache
<VirtualHost *:80>
    ServerName tudominio.com
    DocumentRoot /ruta/a/winoji/public

    <Directory /ruta/a/winoji/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Habilitar mod_rewrite: `sudo a2enmod rewrite && sudo systemctl restart apache2`

## Integración con SonarQube (opcional)

Winoji puede conectarse a una instancia de SonarQube para mostrar métricas de calidad de código directamente en la página del proyecto.

### Configuración

1. En SonarQube, crea un token de acceso en **My Account → Security → Generate Token**
2. En Winoji, ve a la página del proyecto → sección **SonarQube** → introduce la URL de tu instancia, el token y la clave del proyecto (`sonar.projectKey`)
3. Winoji mostrará automáticamente: ratings de seguridad/fiabilidad/mantenibilidad (A–E), bugs, vulnerabilidades, hotspots, code smells, duplicados, cobertura, deuda técnica y líneas de código

### Requisitos

- SonarQube Community Edition o superior
- El servidor SonarQube debe ser accesible desde el servidor donde corre Winoji
- PHP necesita la extensión `curl` habilitada

## Variables de entorno

| Variable | Descripción | Ejemplo |
|---|---|---|
| `APP_ENV` | `development` o `production` | `production` |
| `APP_URL` | URL completa al directorio `public/` | `https://app.ejemplo.com/public` |
| `DB_HOST` | Host de MySQL | `localhost` |
| `DB_NAME` | Nombre de la base de datos | `teamapp` |
| `DB_USER` | Usuario de la DB | `winoji_user` |
| `DB_PASS` | Contraseña de la DB | `contraseñasegura` |
| `ENCRYPT_KEY` | Clave de 32 caracteres para cifrado | (`openssl rand -hex 16`) |
| `SMTP_HOST` | Servidor SMTP | `smtp.gmail.com` |
| `SMTP_PORT` | Puerto SMTP (587 STARTTLS, 25 plain) | `587` |
| `SMTP_USER` | Usuario / email SMTP | `noreply@ejemplo.com` |
| `SMTP_PASS` | Contraseña SMTP | `apppassword` |
| `SMTP_FROM` | Email del remitente | `noreply@ejemplo.com` |
| `SMTP_FROM_NAME` | Nombre del remitente | `Winoji` |

## Seguridad

- Nunca subas `.env` a git — está en `.gitignore` por defecto
- Genera un `ENCRYPT_KEY` fuerte: `openssl rand -hex 16`
- En producción, habilita HTTPS y descomenta la redirección en `public/.htaccess`
- Los tokens de GitHub se almacenan cifrados con AES-256-CBC en la base de datos

## Migraciones

Después de la instalación inicial, aplica cambios de esquema con:

```bash
php db/migrate.php
```

Los archivos de migración están en `db/migrations/`. Cada archivo se aplica una sola vez y queda registrado en la tabla `_migrations`.

## Logs

Los logs de la aplicación se escriben en `storage/logs/app.log` (formato JSON lines).

```bash
tail -f storage/logs/app.log | jq .
```
