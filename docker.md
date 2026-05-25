# Docker Guide

Tài liệu này tập trung vào vận hành Docker cho `Clickon Audit`, đặc biệt là:

- chạy lần đầu
- rebuild website sau khi sửa code
- rebuild toàn bộ stack
- update sau `git pull`
- seed tài khoản quản trị
- xem log, migrate và xử lý lỗi thường gặp

Tài liệu này áp dụng cho stack production hiện tại — **toàn bộ dịch vụ ứng dụng chỉ chạy trong Docker**:

- `mysql`
- `api`
- `queue`
- `web`
- `nginx`

Trên host **không cần** cài PHP, Node.js, MySQL hay Nginx riêng để chạy app (chỉ cần **Docker Engine** + plugin **Compose**).

- **Nginx trong Docker** là reverse proxy của stack (chia `/` → Next.js, `/backend/` → Laravel).
- **Apache / Nginx trên host** chỉ là **tuỳ chọn** khi bạn muốn dùng chung máy với site khác hoặc SSL (Certbot) tại host — xem **Mode B**.

Tài liệu dùng đường dẫn tương đối trong repo:

- [`docker-compose.prod.yml`](./docker-compose.prod.yml)
- [`deploy/env/docker.prod.example`](./deploy/env/docker.prod.example)
- [`deploy/scripts/prod-first-run.sh`](./deploy/scripts/prod-first-run.sh)
- [`deploy/scripts/prod-update.sh`](./deploy/scripts/prod-update.sh)
- [`deploy/scripts/prod-seed-admin.sh`](./deploy/scripts/prod-seed-admin.sh)

## 1. Chuẩn bị — chọn chế độ publish cổng

### Mode A — Chỉ Docker (Docker-only)

Dùng khi VPS **chủ yếu chỉ chạy Clickon Audit** và bạn muốn traffic vào **thẳng container `nginx`** (HTTP), **không** cần Apache/Nginx host làm reverse proxy.

**1.** Trong `deploy/env/docker.prod.env`:

```bash
NGINX_BIND=0.0.0.0
NGINX_HTTP_PORT=80
```

**2.** Đảm bảo cổng 80 **chưa** bị dịch vụ khác chiếm:

```bash
sudo ss -ltnp | grep ':80 '
```

Nếu thấy `apache2` hoặc `nginx` của hệ thống, cần tắt site đó, đổi cổng, hoặc chuyển sang **Mode B**.

**3.** Firewall (nếu dùng `ufw`):

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

**4.** Truy cập: `http://<domain hoặc IP>/` — stack Docker xử lý toàn bộ.

**HTTPS:** file compose hiện tại chỉ có Nginx container listen **HTTP (80)**. Để HTTPS bạn có thể:

- dùng **Cloudflare / load balancer** terminate SSL rồi forward HTTP về cổng 80 VPS; hoặc
- mở rộng stack (Caddy, Traefik, v.v. — ngoài phạm vi mặc định); hoặc
- dùng **Mode B**: SSL trên host, proxy vào Docker (không còn “thuần một lớp container” cho TLS).

### Mode B — Docker + reverse trên host (Apache/Nginx)

Dùng khi **cùng máy** còn site khác, hoặc bạn muốn **Certbot/SSL trên host**, **không** để container chiếm `80/443` công khai.

Trong `deploy/env/docker.prod.env`:

```bash
NGINX_BIND=127.0.0.1
NGINX_HTTP_PORT=18080
```

Host reverse proxy toàn bộ URI vào `http://127.0.0.1:18080/`.

Nếu host dùng **Nginx**, tham khảo:

- [`deploy/nginx/audit.clickon.vn.host-to-docker.conf`](./deploy/nginx/audit.clickon.vn.host-to-docker.conf)

Nếu host dùng **Apache** như server hiện tại, tham khảo:

- [`deploy/apache/audit.clickon.vn.host-to-docker.conf`](./deploy/apache/audit.clickon.vn.host-to-docker.conf)

Lệnh Apache mẫu:

```bash
sudo a2enmod proxy proxy_http headers rewrite ssl
sudo cp deploy/apache/audit.clickon.vn.host-to-docker.conf /etc/apache2/sites-available/audit.clickon.vn.conf
sudo a2ensite audit.clickon.vn.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
sudo certbot --apache -d audit.clickon.vn
```

Các mục từ mục 2 trở đi áp dụng cho **cả hai mode**; khác nhau chỉ chỗ publish cổng như trên.

---

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
- `GEMINI_API_KEY` nếu dùng Gemini hoặc Gemini Deep Research
- `AUDIT_USE_JINA_READER=true` nếu muốn ưu tiên Jina Reader khi crawl URL
- `JINA_API_KEY` nếu tài khoản Jina của bạn yêu cầu API key

Nếu muốn seed admin nhanh bằng script, điền thêm:

- `ADMIN_SEED_EMAIL`
- `ADMIN_SEED_PASSWORD`
- `ADMIN_SEED_NAME`
- `AUTO_SEED_ADMIN=1`

Đặt service account JSON vào:

```bash
app/storage/app/firebase-service-account.json
```

File này được mount cho cả `api`, `queue` và `web`. `web` cũng cần nó vì route `POST /api/auth/session` dùng Firebase Admin để tạo session cookie.

### Biến môi trường cho audit automation

Backend `api` và `queue` dùng các biến này khi crawl và gọi AI:

```bash
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-5.5
OPENAI_REASONING_EFFORT=medium
OPENAI_TIMEOUT_SECONDS=180
AUDIT_STEP2_AI_MODEL=
AUDIT_STEP3_AI_MODEL=

GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-pro
GEMINI_DEEP_RESEARCH_AGENT=deep-research-preview-04-2026
GEMINI_TIMEOUT_SECONDS=180
GEMINI_DEEP_RESEARCH_TIMEOUT_SECONDS=0
AUDIT_BATCH_JOB_TIMEOUT_SECONDS=0
AUDIT_AI_HTTP_TIMEOUT_SECONDS=0
AUDIT_AI_HTTP_CONNECT_TIMEOUT_SECONDS=30
AUDIT_AI_HTTP_RETRY_ATTEMPTS=3
AUDIT_AI_HTTP_RETRY_SLEEP_MS=2000
AUDIT_MAX_AI_STEP_RESPONSE_BYTES=0
DB_QUEUE_RETRY_AFTER=604800
QUEUE_WORKERS=3

AUDIT_MAX_CONTENT_CHARS=18000
AUDIT_MAX_CATEGORY_CONTENT_CHARS=7000
AUDIT_USE_JINA_READER=true
AUDIT_JINA_BASE_URL=https://r.jina.ai/
JINA_API_KEY=
AUDIT_FIRESTORE_SYNC=true
AUDIT_FIRESTORE_FALLBACK=false
```

Ghi chú vận hành:

- `openai` và `gemini` phù hợp chạy nhiều URL vì trả JSON có cấu trúc nhanh hơn.
- Audit URL-only hiện chạy theo chunk: bước 2 mặc định 60 URL/lần gọi AI, bước 3 mặc định 30 URL/lần gọi AI. Admin có thể đổi hai số này tại `/admin/settings`.
- `AUDIT_FIRESTORE_SYNC=true` chỉ dùng để bắn tín hiệu realtime nhỏ vào `auditRunSignals`; dữ liệu thật vẫn đọc/ghi từ MySQL qua Laravel API. `AUDIT_FIRESTORE_FALLBACK=false` để tránh fallback sang Firestore làm trang bị delay.
- `AUDIT_AI_HTTP_CONNECT_TIMEOUT_SECONDS` xử lý riêng timeout kết nối/DNS đến OpenAI/Gemini; nên để 30–60 giây trên VPS mạng yếu.
- `AUDIT_AI_HTTP_RETRY_ATTEMPTS` và `AUDIT_AI_HTTP_RETRY_SLEEP_MS` giúp retry lỗi mạng tạm thời như DNS resolving timeout, không dùng để bypass quota/rate limit.
- `DB_QUEUE_RETRY_AFTER=604800` nghĩa là job đang chạy được giữ tối đa 7 ngày trước khi Laravel xem là mất worker và đưa lại vào queue. Không đặt thấp hơn thời gian audit dài nhất, nếu không có thể bị chạy trùng job.
- `QUEUE_WORKERS` là số worker queue chạy song song trong Docker. `maxParallelItems` trong admin là số batch AI được phép chạy song song; hiệu lực thực tế không vượt quá số worker queue.
- `gemini_deep_research` chạy qua Interactions API nền, có thể mất lâu theo từng chunk; chỉ chọn khi thật sự cần phân tích nghiên cứu sâu và đã có quota.
- Checklist mặc định của bước 3 nằm tại `app/resources/audit/seo-checklist.txt`; nếu user không nhập checklist riêng trong form audit thì backend dùng file này.

### Cấu hình thời gian chạy audit

Mặc định production đang để audit chạy dài gần như không giới hạn:

```bash
AUDIT_BATCH_JOB_TIMEOUT_SECONDS=0
AUDIT_AI_HTTP_TIMEOUT_SECONDS=0
GEMINI_DEEP_RESEARCH_TIMEOUT_SECONDS=0
DB_QUEUE_RETRY_AFTER=604800
```

Ý nghĩa:

- `0` ở các biến timeout audit = không giới hạn ở tầng Laravel job / HTTP AI / Deep Research polling.
- `DB_QUEUE_RETRY_AFTER=604800` = 7 ngày, dùng để tránh Laravel đưa lại job dài vào queue và chạy trùng.
- `AUDIT_AI_HTTP_CONNECT_TIMEOUT_SECONDS` vẫn nên có giới hạn 30–60 giây vì đây là thời gian mở kết nối/DNS; nếu để treo vô hạn thì lỗi mạng có thể làm worker kẹt mãi.

Nếu muốn giới hạn đúng 23 giờ 59 phút, dùng `86340` giây:

```bash
AUDIT_BATCH_JOB_TIMEOUT_SECONDS=86340
AUDIT_AI_HTTP_TIMEOUT_SECONDS=86340
GEMINI_DEEP_RESEARCH_TIMEOUT_SECONDS=86340
DB_QUEUE_RETRY_AFTER=86400
```

Sau khi đổi các biến này, recreate queue/API:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --force-recreate api queue
```
- Nếu đổi các biến AI/crawl, rebuild hoặc recreate `api` và `queue` để queue worker nhận env mới.

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
3. tạo/cập nhật user tương ứng trong MySQL `app_users` với role `admin` để Laravel API nhận đúng quyền
4. giữ nguyên credit hiện có nếu tài khoản đã từng tồn tại

### Chạy artisan trực tiếp

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env --profile tools run --rm artisan clickon:create-admin admin@audit.clickon.vn 'StrongPassword123!' --name='Clickon Audit Admin'
```

Nếu bạn đã có sẵn một Firebase UID và chỉ muốn promote role admin trong MySQL:

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

## 15. Lỗi DNS/timeout khi gọi OpenAI hoặc Gemini

Ví dụ lỗi:

```text
cURL error 28: Resolving timed out after 10010 milliseconds for https://generativelanguage.googleapis.com/...
cURL error 6: Could not resolve host: generativelanguage.googleapis.com
```

Ý nghĩa:

- container `queue` hoặc `api` không resolve DNS đến Google/OpenAI kịp thời
- đây là lỗi network/DNS của Docker hoặc VPS, không phải lỗi format prompt, JSON hay credit
- Gemini Deep Research dễ gặp hơn vì chạy lâu và phải poll endpoint `interactions/{id}` nhiều lần

Kiểm tra DNS từ container:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env exec queue getent hosts generativelanguage.googleapis.com
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env exec queue cat /etc/resolv.conf
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env run --rm --no-deps artisan tinker --execute='echo gethostbyname("generativelanguage.googleapis.com").PHP_EOL;'
```

Kiểm tra gọi API:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env exec queue sh -lc 'curl -v --connect-timeout 30 "https://generativelanguage.googleapis.com/v1beta/models?key=$GEMINI_API_KEY"'
```

Stack production đã cấu hình DNS công khai `8.8.8.8` và `1.1.1.1` cho `api`, `queue`, `artisan`. Sau khi cập nhật compose, recreate service:

```bash
docker compose -f docker-compose.prod.yml --env-file deploy/env/docker.prod.env up -d --force-recreate api queue
```

Nếu VPS vẫn timeout:

- tăng `AUDIT_AI_HTTP_CONNECT_TIMEOUT_SECONDS=60`
- giảm `QUEUE_WORKERS=1` hoặc giảm `maxParallelItems` trong `/admin/settings`
- đổi provider sang `gemini` hoặc `openai` thường nếu Deep Research không ổn định trên mạng hiện tại
- kiểm tra firewall/DNS provider của VPS có chặn hoặc làm chậm `generativelanguage.googleapis.com`
- nếu `getent hosts` không ra IP hoặc `gethostbyname` trả lại nguyên hostname, DNS trong container chưa hoạt động; cần kiểm tra Docker daemon/VPS network trước khi chạy audit lại

## 16. Không nên commit các file này vào Git

Đã được ignore:

- `web/.next`
- `web/node_modules`
- `web/.env.local`
- `deploy/env/docker.prod.env`
- `app/storage/app/firebase-service-account.json`

Nếu sau này thấy các file này quay lại Git, cần dọn khỏi index trước khi push.
