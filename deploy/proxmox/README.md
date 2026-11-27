# Despliegue en Proxmox LXC + Cloudflare Tunnel (Usuarios Externos)

Esta guía levanta toda la plataforma web de **PaceFriend** (Nginx + PHP 8.4-FPM con OPcache JIT + Queue Worker + PostgreSQL 17 + `cloudflared`) dentro de un contenedor LXC ligero en Proxmox, accesible desde Internet con HTTPS automático sin abrir puertos en tu router.

---

## 1. Crear el Contenedor LXC en Proxmox

Desde la interfaz web de Proxmox (**Create CT**):
- **Template**: `debian-12-standard` o `debian-13-standard` (o Ubuntu 24.04).
- **Unprivileged container**: Sí (marcado).
- **Options → Features**: Marca **`nesting=1`** *(imprescindible para que Docker corra dentro de LXC)*.
- **Recursos recomendados**:
  - **CPU**: 2 cores
  - **RAM**: 2048 MB (512 MB swap)
  - **Disco**: 16 GB – 32 GB (para almacenar base de datos `jsonb` y caché de voces IA)
  - **Red**: DHCP o IP fija en tu LAN.

---

## 2. Desplegar PaceFriend dentro del LXC

Entra por consola/SSH al contenedor LXC como `root`:

```bash
apt update && apt install -y git
git clone <URL_DE_TU_REPOSITORIO> /opt/pacefriend
cd /opt/pacefriend
chmod +x deploy/proxmox/setup-lxc.sh
./deploy/proxmox/setup-lxc.sh
```

En un par de minutos tendrás la app corriendo en `http://<IP_DEL_LXC>:8080`.

---

## 3. Exponerlo a Usuarios Externos con Cloudflare Tunnel

Usar **Cloudflare Tunnel** es la opción ideal porque evita abrir los puertos `80/443` de tu casa, sortea CG-NAT y te da certificado TLS/HTTPS gratis:

1. Entra en [Cloudflare Zero Trust](https://one.dash.cloudflare.com/) → **Networks** → **Tunnels** → **Create a tunnel** (tipo *Cloudflared*).
2. Ponle nombre (ej. `pacefriend-proxmox`) y **copia el Token** (`eyJhIjoi...`).
3. En la pestaña **Public Hostname** del túnel, añade:
   - **Subdomain / Domain**: ej. `pacefriend.tudominio.com`
   - **Service Type**: `HTTP`
   - **URL**: `app:80` *(al correr `cloudflared` en nuestro mismo `docker-compose.yml`, resuelve `app:80` directamente por la red interna de Docker).*
4. En el contenedor LXC, edita `/opt/pacefriend/.env`:
   ```env
   APP_URL=https://pacefriend.tudominio.com
   COMPOSE_PROFILES=tunnel
   CLOUDFLARE_TUNNEL_TOKEN=eyJhIjoi...tu_token_aqui...
   ```
5. Aplica los cambios:
   ```bash
   docker compose up -d
   ```

¡Listo! Tanto tú como cualquier piloto externo podréis registraros en `https://pacefriend.tudominio.com`, descargar vuestro `pacefriend.json` y conectar `pacefriend-client.exe` desde cualquier parte del mundo sobre HTTPS.

---

## Actualizar en el futuro cuando hagas cambios

```bash
cd /opt/pacefriend
git pull
docker compose up -d --build
```
*(El `entrypoint.sh` ejecuta automáticamente las nuevas migraciones y `php artisan tracks:sync` sin perder vueltas ni audios cacheados).*
