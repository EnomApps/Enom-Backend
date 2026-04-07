#!/bin/bash
# ENOM Mood Detection Service - Setup Script
# Run on AWS EC2: sudo bash setup.sh

set -e

echo "=== ENOM Mood Detection Service Setup ==="

# 1. Install Python and pip
echo "[1/5] Installing Python..."
sudo apt update
sudo apt install -y python3 python3-pip python3-venv

# 2. Create virtual environment
echo "[2/5] Creating virtual environment..."
cd /var/www/enom/mood-service
python3 -m venv venv
source venv/bin/activate

# 3. Install dependencies
echo "[3/5] Installing Python packages (this may take a few minutes)..."
pip install --upgrade pip
pip install -r requirements.txt

# 4. Create systemd service
echo "[4/5] Creating systemd service..."
sudo tee /etc/systemd/system/mood-service.service > /dev/null <<EOF
[Unit]
Description=ENOM Mood Detection Service
After=network.target redis.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/enom/mood-service
Environment="PATH=/var/www/enom/mood-service/venv/bin"
Environment="LARAVEL_API_URL=http://127.0.0.1"
Environment="REDIS_HOST=127.0.0.1"
Environment="MOOD_PORT=8001"
ExecStart=/var/www/enom/mood-service/venv/bin/python -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# 5. Configure Nginx reverse proxy
echo "[5/5] Configuring Nginx..."
sudo tee /etc/nginx/snippets/mood-service.conf > /dev/null <<EOF
location /api/v1/mood/ {
    proxy_pass http://127.0.0.1:8001;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_read_timeout 30s;
    proxy_connect_timeout 10s;
}
EOF

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Next steps:"
echo "  1. Add this line inside your Nginx server block:"
echo "     include snippets/mood-service.conf;"
echo ""
echo "  2. Start the service:"
echo "     sudo systemctl daemon-reload"
echo "     sudo systemctl enable mood-service"
echo "     sudo systemctl start mood-service"
echo "     sudo systemctl restart nginx"
echo ""
echo "  3. Check status:"
echo "     sudo systemctl status mood-service"
echo "     curl http://127.0.0.1:8001/api/v1/mood/health"
echo ""
echo "  4. View logs:"
echo "     sudo journalctl -u mood-service -f"
