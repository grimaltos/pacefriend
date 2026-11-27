#!/usr/bin/env bash
set -euo pipefail

echo "=========================================================="
echo " PaceFriend — Preparación de Contenedor LXC (Debian/Ubuntu)"
echo "=========================================================="

if [ "$(id -u)" -ne 0 ]; then
    echo "Ejecuta este script como root dentro del contenedor LXC."
    exit 1
fi

# 1. Instalar Docker Engine + Docker Compose plugin si no están instalados
if ! command -v docker >/dev/null 2>&1; then
    echo "[1/3] Instalando Docker Engine y Docker Compose..."
    apt-get update
    apt-get install -y ca-certificates curl gnupg openssl git
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
else
    echo "[1/3] Docker ya está instalado."
fi

# 2. Preparar archivo .env con claves criptográficas aleatorias si no existe
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${REPO_ROOT}"

if [ ! -f .env ]; then
    echo "[2/3] Generando .env inicial con APP_KEY y DB_PASSWORD aleatorios..."
    cp .env.proxmox.example .env
    RAND_KEY="base64:$(openssl rand -base64 32)"
    RAND_DB_PASS="$(openssl rand -hex 16)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${RAND_KEY}|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${RAND_DB_PASS}|" .env
    echo " -> Archivo .env creado. Edita APP_URL, CLOUDFLARE_TUNNEL_TOKEN y tus API keys con: nano .env"
else
    echo "[2/3] Usando archivo .env existente."
fi

# 3. Construir y levantar los contenedores
echo "[3/3] Construyendo y arrancando PaceFriend..."
docker compose up -d --build

IP_ADDR="$(hostname -I | awk '{print $1}')"
echo ""
echo "=========================================================="
echo " ¡PaceFriend desplegado correctamente!"
echo " - Acceso LAN local:  http://${IP_ADDR}:8080"
echo " - Para activar Cloudflare Tunnel:"
echo "   1. Edita .env, descomenta COMPOSE_PROFILES=tunnel y pega CLOUDFLARE_TUNNEL_TOKEN"
echo "   2. Ejecuta: docker compose up -d"
echo "=========================================================="
