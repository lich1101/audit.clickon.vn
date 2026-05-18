# Clickon Audit

Clickon Audit là SaaS quản lý credit và audit website.

- `web/`: Next.js App Router, React, TailwindCSS, Firebase Authentication, Firestore realtime
- `app/`: Laravel API, MySQL, queue worker, OpenAI audit pipeline
- `firestore.rules`: rule bảo mật Firestore
- `deploy/nginx/audit.clickon.vn.conf`: Nginx host proxy **trực tiếp** tới Next.js `3000` + Laravel `8000` (mode cũ, không phải mode Docker stack khuyến nghị)
- `deploy/nginx/audit.clickon.vn.host-to-docker.conf`: Nginx host proxy tới **Nginx Docker** (`127.0.0.1:18080` mặc định) khi bạn muốn SSL/public qua host
- `docker-compose.prod.yml`: stack Docker production cho `web`, `api`, `queue`, `mysql`
- `deploy/env/docker.prod.example`: file env mẫu cho Docker production
- `deploy/scripts/prod-first-run.sh`: script chạy lần đầu cho stack production
- `deploy/scripts/prod-update.sh`: script update production từ Git + Docker
- `web/Dockerfile.prod`: image production cho Next.js
- `app/docker/php/Dockerfile.prod`: image production cho Laravel API / queue

## Kiến trúc hiện tại

- Frontend chạy tại `https://audit.clickon.vn`
- Laravel API chạy sau reverse proxy tại `https://audit.clickon.vn/backend`
- Next.js route nội bộ vẫn dùng bình thường tại `/api/...`
- Tất cả app services production đều có thể chạy trong Docker:
  - `mysql`
  - `api`
  - `queue`
  - `web`
  - `nginx`
- Nginx host chỉ là lựa chọn thêm cho public SSL / multi-site, không phải bắt buộc của app stack
- Laravel queue xử lý từng `audit run` theo từng URL
- Firestore realtime sync:
  - `users`
  - `plans`
  - `websites`
  - `websiteAudits`
  - `creditLogs`
  - `auditRuns`
  - `auditRunItems`

## Setup local nhanh

1. Tạo Firebase project và bật:
   - Authentication với Email/Password
   - Firestore Database
2. Tạo service account JSON.
3. Copy env:
```bash
cp web/.env.example web/.env.local
cp app/.env.example app/.env
```
4. Điền Firebase config vào `web/.env.local`.
5. Đặt service account JSON vào `app/storage/app/firebase-service-account.json`.
6. Cài dependencies:
```bash
cd web && npm install
cd ../app && composer install
```
7. Chạy MySQL, API, queue:
```bash
docker compose up -d mysql api queue
docker compose run --rm composer php artisan key:generate
docker compose run --rm composer php artisan migrate --force
```
8. Chạy frontend:
```bash
cd web
npm run dev
```

## Deploy Ubuntu với domain `audit.clickon.vn`

Hướng dẫn này giả định:

- Ubuntu `22.04` hoặc `24.04`
- code đặt tại `/var/www/clickon-audit` hoặc `/var/www/audit.clickon.vn` (thay `<repo>` bên dưới cho đúng đường dẫn)
- domain public là `audit.clickon.vn`
- `docker-compose.prod.yml`: có service **nginx** bên trong Docker; mặc định chỉ bind `127.0.0.1:18080` để **không đụng** Nginx site khác trên cùng máy
- Nginx trên host: bật SSL (Certbot) và `proxy_pass` vào `127.0.0.1:18080` — xem file `deploy/nginx/audit.clickon.vn.host-to-docker.conf`
- (Tuỳ chọn) Nếu không dùng Nginx trong Docker: publish cổng `web`/`api` và dùng `audit.clickon.vn.conf` như cũ

## 1. Chuẩn bị DNS

Tạo bản ghi:

- `A audit.clickon.vn -> <server_public_ip>`

Kiểm tra:

```bash
dig +short audit.clickon.vn
```

## 2. Cài package hệ thống

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg lsb-release nginx certbot python3-certbot-nginx git unzip
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

Cài Docker:

```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER
```

Đăng xuất rồi đăng nhập lại để group `docker` có hiệu lực.

## 3. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

## 4. Clone source

```bash
sudo mkdir -p /var/www
sudo chown -R $USER:$USER /var/www
cd /var/www
git clone <repo-url> clickon-audit
cd /var/www/clickon-audit
```

## 5. Tạo file env production

Docker production dùng 1 file env chung.

Tạo file:

```bash
cp deploy/env/docker.prod.example deploy/env/docker.prod.env
```

Sửa đầy đủ giá trị trong:

```bash
deploy/env/docker.prod.env
```

Các biến quan trọng cần thay:

```bash
NEXT_PUBLIC_FIREBASE_API_KEY=your-firebase-web-api-key
NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN=your-project.firebaseapp.com
NEXT_PUBLIC_FIREBASE_PROJECT_ID=your-firebase-project-id
NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET=your-project.appspot.com
NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID=1234567890
NEXT_PUBLIC_FIREBASE_APP_ID=1:1234567890:web:abcdef123456

NEXT_PUBLIC_LARAVEL_API_URL=https://audit.clickon.vn/backend
LARAVEL_API_URL=http://api:8000
LARAVEL_INTERNAL_API_KEY=change-this-to-a-long-random-secret

FIREBASE_PROJECT_ID=your-firebase-project-id
FIREBASE_CLIENT_EMAIL=firebase-adminsdk-xxxxx@your-project.iam.gserviceaccount.com
FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nYOUR_PRIVATE_KEY\n-----END PRIVATE KEY-----\n"
APP_KEY=base64:replace-me
DB_PASSWORD=change-db-password
MYSQL_ROOT_PASSWORD=change-root-password
OPENAI_API_KEY=sk-...
```

## 6. Thêm Firebase service account

Tạo thư mục nếu chưa có:

```bash
mkdir -p /var/www/clickon-audit/app/storage/app
```

Copy file JSON vào:

```bash
/var/www/clickon-audit/app/storage/app/firebase-service-account.json
```

## 7. Firebase cấu hình production

Trong Firebase Console:

1. Authentication:
   - bật `Email/Password`
   - thêm authorized domain `audit.clickon.vn`
2. Firestore:
   - tạo database ở production mode
3. Deploy rules:

```bash
npm install -g firebase-tools
firebase login
cd /var/www/clickon-audit
firebase deploy --only firestore:rules
```

## 8. Cài dependencies dự án

Tạo `APP_KEY` nếu chưa có:

```bash
cd /var/www/clickon-audit
openssl rand -base64 32
```

Sau đó thêm tiền tố `base64:` và dán vào `APP_KEY` trong `deploy/env/docker.prod.env`.

Build và chạy stack Docker production:

```bash
cd /var/www/<repo>
bash deploy/scripts/prod-first-run.sh
```

Kiểm tra container:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env ps
```

Kiểm tra Nginx **trong Docker** (mặc định `127.0.0.1:18080`, xem `NGINX_HTTP_PORT` / `NGINX_BIND` trong `docker.prod.env`):

```bash
curl -sI -H 'Host: audit.clickon.vn' http://127.0.0.1:18080/
curl -sI -H 'Host: audit.clickon.vn' http://127.0.0.1:18080/backend/up
```

Nếu bạn **không** dùng Nginx trong Docker mà publish thẳng `web`/`api` ra host, thay `18080` bằng `3000`/`8000` tương ứng (không khuyến nghị với `docker-compose.prod.yml` hiện tại).

## 9. Cấu hình Nginx host (public + SSL)

Có **hai** cách; chỉ chọn **một** để tránh proxy lệch cổng.

### Cách A — Khuyến nghị: stack `docker-compose.prod.yml` (Nginx trong Docker)

1. Đảm bảo stack đã chạy và cổng upstream đúng (mặc định `18080`).
2. Trên host, dùng file proxy **một** tầng lên Docker:

```bash
sudo cp /var/www/<repo>/deploy/nginx/audit.clickon.vn.host-to-docker.conf /etc/nginx/sites-available/audit.clickon.vn.conf
sudo ln -sf /etc/nginx/sites-available/audit.clickon.vn.conf /etc/nginx/sites-enabled/audit.clickon.vn.conf
```

Nếu bạn đổi `NGINX_HTTP_PORT` trong `docker.prod.env`, sửa dòng `proxy_pass http://127.0.0.1:18080` trong file trên cho khớp.

3. Kiểm tra và nạp lại Nginx host:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Cách B — Không có Nginx trong Docker: proxy thẳng tới Next + Laravel

Chỉ khi bạn tự publish `127.0.0.1:3000` và `127.0.0.1:8000` (ví dụ chỉnh compose / chạy process ngoài Docker):

```bash
sudo cp /var/www/<repo>/deploy/nginx/audit.clickon.vn.conf /etc/nginx/sites-available/audit.clickon.vn.conf
sudo ln -sf /etc/nginx/sites-available/audit.clickon.vn.conf /etc/nginx/sites-enabled/audit.clickon.vn.conf
sudo nginx -t
sudo systemctl reload nginx
```

### Xử lý khi **vẫn thấy site khác** (ví dụ "ChatPlus", app không phải Clickon Audit)

Điều này gần như luôn do **Nginx host** đang phục vụ `default_server` hoặc **chưa có** `server_name audit.clickon.vn` trùng hostname bạn gõ trên trình duyệt.

1. Kiểm tra DNS trỏ đúng máy bạn đang cấu hình:

```bash
dig +short audit.clickon.vn
```

2. Liệt kê các `server` đang listen 80/443 và `server_name`:

```bash
sudo nginx -T 2>/dev/null | grep -E 'listen |server_name|default_server'
```

3. Đảm bảo **chỉ một** vhost xử lý `audit.clickon.vn` và **không** có site khác là `default_server` cho IP đó trừ khi bạn cố ý.

4. Thử HTTP với Host header (từ máy chủ):

```bash
curl -sI -H 'Host: audit.clickon.vn' http://127.0.0.1/
```

Nếu lệnh này vẫn ra header/cache của app khác → file trong `/etc/nginx/sites-enabled/` chưa đúng hoặc thứ tự include sai; sửa xong `sudo nginx -t && sudo systemctl reload nginx`.

Kiểm tra từ ngoài (sau khi DNS đúng):

```bash
curl -I http://audit.clickon.vn
curl -I http://audit.clickon.vn/backend/up
```

## 10. Cài SSL bằng Certbot

```bash
sudo certbot --nginx -d audit.clickon.vn
```

Nếu trình duyệt vẫn báo **Not secure** / gạch đỏ `https://`: thường là **chưa** chạy Certbot trên đúng `server_name audit.clickon.vn`, hoặc Certbot gắn cert cho **site khác**. Kiểm tra bằng `sudo nginx -T | grep -A2 ssl_certificate` sau khi cài chứng chỉ.

Chọn redirect HTTP sang HTTPS khi Certbot hỏi.

Kiểm tra renew:

```bash
sudo certbot renew --dry-run
```

## 11. Seed admin đầu tiên

Bạn có 2 cách:

### Cách 1. Tạo luôn tài khoản quản trị mới

```bash
cd /var/www/clickon-audit
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan clickon:create-admin admin@audit.clickon.vn 'StrongPassword123!' --name='Clickon Audit Admin'
```

Hoặc dùng script:

```bash
cd /var/www/clickon-audit
bash deploy/scripts/prod-seed-admin.sh
```

Script này sẽ đọc:

- `ADMIN_SEED_EMAIL`
- `ADMIN_SEED_PASSWORD`
- `ADMIN_SEED_NAME`
- `AUTO_SEED_ADMIN`

trong `deploy/env/docker.prod.env`.

### Cách 2. Promote một user Firebase có sẵn thành admin

1. Mở `https://audit.clickon.vn/register`
2. Đăng ký tài khoản đầu tiên
3. Lấy `uid` của user trong Firebase Authentication
4. Chạy lệnh:

```bash
cd /var/www/clickon-audit
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan clickon:seed-admin <firebase_uid> <email>
```

Lệnh này sẽ upsert `users/{uid}` trong Firestore với:

- `role = admin`
- `credits = 0`

Tài liệu Docker chi tiết, gồm cả rebuild website sau khi sửa code, nằm ở [docker.md](</Users/macbook/Desktop/php/web audit/docker.md>).

## 12. Kiểm tra sau deploy

Truy cập:

- `https://audit.clickon.vn/login`
- `https://audit.clickon.vn/register`
- `https://audit.clickon.vn/dashboard`
- `https://audit.clickon.vn/admin`

Kiểm tra thêm:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f web
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f api
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f queue
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f mysql
```

## 13. Update ứng dụng

```bash
cd /var/www/clickon-audit
bash deploy/scripts/prod-update.sh
sudo systemctl reload nginx
```

Nếu bạn deploy theo branch khác `main`, có thể truyền tên branch:

```bash
bash deploy/scripts/prod-update.sh develop
```

Nếu source đã được `git pull` sẵn bởi pipeline CI/CD, có thể bỏ bước pull:

```bash
SKIP_PULL=1 bash deploy/scripts/prod-update.sh
```

## 16. Luồng audit SEO đang chạy như thế nào

1. User vào `/websites/[id]/audit`
2. Lưu:
   - danh sách article URLs
   - danh sách categories
3. Tạo `audit run`
4. Frontend gửi request tới `POST https://audit.clickon.vn/backend/api/audit-runs`
5. Laravel queue xử lý từng URL:
   - `queued`
   - `fetching`
   - `analyzing`
   - `completed` hoặc `failed`
6. Kết quả sync sang Firestore:
   - `auditRuns/{publicId}`
   - `auditRunItems/{publicId}`
7. Frontend nghe realtime và có thể export `.xlsx`

## 17. Lưu ý kỹ thuật

- Với repo hiện tại, Laravel đang chạy bằng `php artisan serve` trong container.
- Cách này đủ để triển khai thực tế ở mức nhỏ và trung bình.
- Nếu cần production cứng hơn, nên đổi riêng phần API sang `nginx + php-fpm` trong container.
- Không route toàn bộ `/api` về Laravel, vì Next.js đang có API route riêng.
- Vì vậy production dùng prefix `/backend` cho Laravel là an toàn nhất với code hiện tại.

## API hiện có

- `POST /backend/api/credits/add`
- `POST /backend/api/credits/subtract`
- `GET /backend/api/credits/balance?userId=...`
- `POST /backend/api/audit-runs`
- `GET /backend/api/audit-runs/{publicId}`
- `GET /backend/api/plan-requests`
- `POST /backend/api/plan-requests`
- `GET /backend/api/admin/plan-requests`
- `POST /backend/api/admin/plan-requests/{id}/approve`
- `POST /backend/api/admin/plan-requests/{id}/reject`
