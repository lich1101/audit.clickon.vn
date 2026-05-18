# Clickon Audit

Clickon Audit là SaaS quản lý credit và audit website.

- `web/`: Next.js App Router, React, TailwindCSS, Firebase Authentication, Firestore realtime
- `app/`: Laravel API, MySQL, queue worker, OpenAI audit pipeline
- `firestore.rules`: rule bảo mật Firestore
- `deploy/nginx/audit.clickon.vn.conf`: file mẫu Nginx cho Ubuntu
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
- code đặt tại `/var/www/clickon-audit`
- domain public là `audit.clickon.vn`
- frontend đi qua Nginx tới `127.0.0.1:3000`
- Laravel API đi qua Nginx tới `127.0.0.1:8000` với prefix `/backend`
- `web`, `api`, `queue`, `mysql` chạy hoàn toàn bằng Docker Compose production
- Nginx trên host chỉ làm reverse proxy và SSL termination

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
cd /var/www/clickon-audit
bash deploy/scripts/prod-first-run.sh
```

Chạy migrate:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env ps
```

Kiểm tra services:

```bash
curl -I http://127.0.0.1:3000
curl -I http://127.0.0.1:8000
```

## 9. Cấu hình Nginx

```bash
sudo cp /var/www/clickon-audit/deploy/nginx/audit.clickon.vn.conf /etc/nginx/sites-available/audit.clickon.vn.conf
sudo ln -s /etc/nginx/sites-available/audit.clickon.vn.conf /etc/nginx/sites-enabled/audit.clickon.vn.conf
sudo nginx -t
sudo systemctl reload nginx
```

File này route như sau:

- `/` -> Next.js `127.0.0.1:3000`
- `/backend/` -> Laravel `127.0.0.1:8000`

Kiểm tra:

```bash
curl -I http://audit.clickon.vn
curl -I http://audit.clickon.vn/backend/api/credits/balance
```

## 10. Cài SSL bằng Certbot

```bash
sudo certbot --nginx -d audit.clickon.vn
```

Chọn redirect HTTP sang HTTPS khi Certbot hỏi.

Kiểm tra renew:

```bash
sudo certbot renew --dry-run
```

## 11. Seed admin đầu tiên

1. Mở `https://audit.clickon.vn/register`
2. Đăng ký tài khoản đầu tiên
3. Lấy `uid` của user trong Firebase Authentication
4. Chạy lệnh:

```bash
cd /var/www/clickon-audit
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan clickon:seed-admin <firebase_uid> <email>
```

Lệnh này sẽ upsert `users/{uid}` với:

- `role = admin`
- `credits = 0`

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
