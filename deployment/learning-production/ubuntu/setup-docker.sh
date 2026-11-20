#!/usr/bin/env bash
# Run inside the Ubuntu 24.04 VM. Installs Docker Engine from the official repository.
set -euo pipefail

echo "== Installing prerequisites =="
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg jq htop cifs-utils

echo "== Adding Docker's official GPG key and repository =="
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

. /etc/os-release
sudo tee /etc/apt/sources.list.d/docker.sources > /dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io \
    docker-buildx-plugin docker-compose-plugin

echo "== Configuring daemon log rotation and live-restore =="
sudo mkdir -p /etc/docker
sudo tee /etc/docker/daemon.json > /dev/null <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "live-restore": true
}
EOF

echo "== Enabling Docker at boot =="
sudo systemctl enable containerd
sudo systemctl enable --now docker
sudo systemctl restart docker

echo "== Verification =="
docker --version
docker compose version
sudo systemctl is-enabled docker
sudo docker run hello-world

echo "Docker Engine is installed and will start automatically after boot."
