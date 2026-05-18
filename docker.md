# Docker Guide

Tài liệu này tập trung vào vận hành Docker cho `Clickon Audit`, đặc biệt là:

- chạy lần đầu
- rebuild website sau khi sửa code
- rebuild toàn bộ stack
- update sau `git pull`
- seed tài khoản quản trị
- xem log, migrate và xử lý lỗi thường gặp

Tài liệu này áp dụng cho stack production hiện tại:

- `mysql`
- `api`
- `queue`
- `web`
- `nginx`

Hiểu đúng về kiến trúc hiện tại:

- **Tất cả app services** đều chạy trong Docker:
  - `mysql`
  - `api`
  - `queue`
  - `web`
  - `nginx`
- Nginx **trong Docker** là reverse proxy nội bộ/public của stack.
- Nginx **trên host** chỉ là phương án **tuỳ chọn** nếu bạn muốn:
  - SSL/Certbot trên máy chủ
  - hoặc đang có nhiều website dùng chung một Nginx host

File chính:

- [docker-compose.prod.yml](</Users/macbook/Desktop/php/web audit/docker-compose.prod.yml>)
- [deploy/env/docker.prod.example](</Users/macbook/Desktop/php/web audit/deploy/env/docker.prod.example>)
- [deploy/scripts/prod-first-run.sh](</Users/macbook/Desktop/php/web audit/deploy/scripts/prod-first-run.sh>)
- [deploy/scripts/prod-update.sh](</Users/macbook/Desktop/php/web audit/deploy/scripts/prod-update.sh>)
- [deploy/scripts/prod-seed-admin.sh](</Users/macbook/Desktop/php/web audit/deploy/scripts/prod-seed-admin.sh>)

## 1. Chuẩn bị

## Chọn mode chạy

### Mode A. Docker-only

Phù hợp khi:

- máy chủ này chỉ chạy `Clickon Audit`
- bạn muốn public trực tiếp bằng chính `nginx` container

Thiết lập trong `deploy/env/docker.prod.env`:

```bash
NGINX_BIND=0.0.0.0
NGINX_HTTP_PORT=80
```

Khi đó:

- truy cập public sẽ đi thẳng vào `nginx` container
- không cần Nginx host để reverse proxy HTTP

Lưu ý:

- mode này mới chỉ xử lý HTTP
- nếu bạn cần HTTPS thật với Let's Encrypt mà vẫn muốn 100% Docker, tôi nên đổi stack sang `caddy` hoặc thêm `certbot`/`traefik` container riêng

### Mode B. Docker + host Nginx

Phù hợp khi:

- máy chủ đang chạy nhiều site
- bạn muốn SSL/Certbot trên host
- bạn không muốn container chiếm cổng `80/443`

Thiết lập trong `deploy/env/docker.prod.env`:

```bash
NGINX_BIND=127.0.0.1
NGINX_HTTP_PORT=18080
```

Khi đó:

- `nginx` trong Docker chỉ lắng nghe nội bộ
- Nginx host sẽ `proxy_pass` tới `127.0.0.1:18080`

Phần bên dưới của tài liệu vẫn dùng được cho cả hai mode. Khác biệt chủ yếu là:

- Docker-only: public trực tiếp cổng `80` từ container
- Docker + host Nginx: public qua Nginx host

Copy env production:

```bash
cp deploy/env/docker.prod.example deploy/env/docker.prod.env
```

Sửa ít nhất các biến sau trong `deploy/env/docker.prod.env`:

- `APP_KEY`
- `DB_PASSWORD`
- `MYSQL_ROOT_PASSWORD`
- `LARAVEL_INTERNAL_API_KEY`
- `FIREBASE_PROJECT_ID`
- `FIREBASE_CLIENT_EMAIL`
- `FIREBASE_PRIVATE_KEY`
- `NEXT_PUBLIC_FIREBASE_API_KEY`
- `NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN`
- `NEXT_PUBLIC_FIREBASE_PROJECT_ID`
- `NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET`
- `NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID`
- `NEXT_PUBLIC_FIREBASE_APP_ID`
- `OPENAI_API_KEY`

Nếu muốn seed admin nhanh bằng script, điền thêm:

- `ADMIN_SEED_EMAIL`
- `ADMIN_SEED_PASSWORD`
- `ADMIN_SEED_NAME`
- `AUTO_SEED_ADMIN=1`

Đặt service account JSON vào:

```bash
app/storage/app/firebase-service-account.json
```

## 2. Chạy lần đầu

```bash
bash deploy/scripts/prod-first-run.sh
```

Script này làm:

1. build image cho `api`, `queue`, `web`
2. khởi động `mysql`, `api`, `queue`, `web`, `nginx`
3. chạy migrate
4. tự seed admin nếu `AUTO_SEED_ADMIN=1` và có đủ biến `ADMIN_SEED_*`
5. in trạng thái container

Kiểm tra:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env ps
```

## 3. Seed tài khoản quản trị

### Cách nhanh bằng env

Nếu đã có:

- `ADMIN_SEED_EMAIL`
- `ADMIN_SEED_PASSWORD`
- `ADMIN_SEED_NAME`

thì chạy:

```bash
bash deploy/scripts/prod-seed-admin.sh
```

### Cách truyền tay

```bash
bash deploy/scripts/prod-seed-admin.sh admin@audit.clickon.vn 'StrongPassword123!' 'Clickon Audit Admin'
```

Script sẽ gọi artisan command:

```bash
clickon:create-admin
```

Command này sẽ:

1. tạo hoặc cập nhật user trong Firebase Authentication
2. đặt lại password nếu user đã tồn tại
3. seed profile `users/{uid}` trong Firestore với role `admin`
4. giữ nguyên credit hiện có nếu tài khoản đã từng tồn tại

### Chạy artisan trực tiếp

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan clickon:create-admin admin@audit.clickon.vn 'StrongPassword123!' --name='Clickon Audit Admin'
```

Nếu bạn đã có sẵn một Firebase UID và chỉ muốn promote role admin trong Firestore:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan clickon:seed-admin <uid> <email> --name='Clickon Audit Admin'
```

### Lỗi thường gặp khi seed admin

#### Lỗi `Audit: command not found`

Nguyên nhân:

- file `deploy/env/docker.prod.env` có biến chứa khoảng trắng nhưng không quote đúng
- ví dụ:
  - `APP_NAME=Clickon Audit API`

Nên sửa thành:

```bash
APP_NAME="Clickon Audit API"
```

Ngoài ra script hiện tại đã được sửa để không `source` toàn bộ env file nữa, nên lỗi này sẽ không còn lặp lại nếu bạn cập nhật code mới.

#### Lỗi `Cannot use SplFileObject with directories`

Nguyên nhân gần như chắc chắn:

- đường dẫn `app/storage/app/firebase-service-account.json` trên host đang là **thư mục**
- trong khi ứng dụng cần một **file JSON**

Kiểm tra:

```bash
ls -ld app/storage/app/firebase-service-account.json
```

Nếu thấy đó là thư mục, sửa bằng:

```bash
rm -rf app/storage/app/firebase-service-account.json
cp /path/to/your/firebase-service-account.json app/storage/app/firebase-service-account.json
```

Kiểm tra lại:

```bash
test -f app/storage/app/firebase-service-account.json && echo OK
```

## 4. Rebuild website sau khi sửa code frontend

Trường hợp bạn chỉ sửa `web/`:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --build web nginx
```

Giải thích:

- `web` cần rebuild image vì Next.js phải build lại bundle production
- `nginx` không luôn bắt buộc rebuild, nhưng cho khởi động lại cùng để upstream ổn định hơn

Nếu chỉ muốn restart không rebuild:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env restart web nginx
```

## 5. Rebuild backend sau khi sửa Laravel

Trường hợp bạn sửa `app/`:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --build api queue nginx
```

Sau đó chạy migrate nếu có thay đổi database:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan migrate --force
```

## 6. Rebuild toàn bộ stack

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --build mysql api queue web nginx
```

Nếu muốn build sạch, bỏ cache:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env build --no-cache api queue web
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d mysql api queue web nginx
```

## 7. Update sau khi `git pull`

Nếu server của bạn quản lý source bằng Git, luồng chuẩn là:

```bash
bash deploy/scripts/prod-update.sh
```

Script này làm:

1. `git fetch`
2. `git pull --ff-only`
3. rebuild stack production
4. chạy migrate
5. in trạng thái container

Nếu deploy branch khác:

```bash
bash deploy/scripts/prod-update.sh develop
```

Nếu code đã được cập nhật sẵn bởi CI/CD hoặc bạn vừa `git pull` tay:

```bash
SKIP_PULL=1 bash deploy/scripts/prod-update.sh
```

## 8. Xem log

### Toàn bộ stack

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f
```

### Frontend

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f web
```

### Laravel API

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f api
```

### Queue audit

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f queue
```

### Nginx container

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f nginx
```

## 9. Chạy artisan trong Docker

Ví dụ clear cache:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan optimize:clear
```

Ví dụ migrate:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan migrate --force
```

Ví dụ list routes:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan route:list
```

## 10. Dừng hoặc khởi động lại

### Stop stack

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env stop
```

### Start lại stack

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env start
```

### Restart một service

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env restart web
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env restart api
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env restart queue
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env restart nginx
```

## 11. Nếu build xong mà website chưa đổi

Kiểm tra theo thứ tự:

1. Có `git pull` đúng branch chưa
2. Có rebuild `web` chưa
3. Có restart `nginx` chưa
4. Trình duyệt có đang cache file cũ không
5. `NEXT_PUBLIC_*` trong env có đổi nhưng chưa rebuild image không

Lệnh an toàn:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --build web nginx
```

## 12. Nếu đổi biến môi trường frontend

Ví dụ đổi:

- `NEXT_PUBLIC_FIREBASE_API_KEY`
- `NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN`
- `NEXT_PUBLIC_FIREBASE_PROJECT_ID`
- `NEXT_PUBLIC_LARAVEL_API_URL`

thì bắt buộc rebuild `web`:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --build web nginx
```

Lý do:

- các biến `NEXT_PUBLIC_*` được bake vào build output của Next.js
- restart đơn thuần là không đủ

## 13. Nếu đổi biến môi trường backend

Ví dụ đổi:

- `OPENAI_API_KEY`
- `FIREBASE_CREDENTIALS`
- `LARAVEL_INTERNAL_API_KEY`
- `DB_*`

thì nên rebuild hoặc ít nhất recreate `api` và `queue`:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --build api queue nginx
```

## 14. Reset mềm khi queue kẹt

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env restart queue
```

Nếu vẫn lỗi:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f queue
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env logs -f api
```

## 15. Không nên commit các file này vào Git

Đã được ignore:

- `web/.next`
- `web/node_modules`
- `web/.env.local`
- `deploy/env/docker.prod.env`
- `app/storage/app/firebase-service-account.json`

Nếu sau này thấy các file này quay lại Git, cần dọn khỏi index trước khi push.
