# Clickon Keyword Rank Checker

Chrome extension dùng để check thứ hạng keyword trên Google bằng máy của người dùng, không chạy scraping trên server/VPS.

## Cài đặt local

1. Mở `chrome://extensions`.
2. Bật `Developer mode`.
3. Chọn `Load unpacked`.
4. Trỏ tới thư mục `chrome/keyword-rank-checker`.
5. Bấm icon extension rồi chọn `Mở công cụ check rank`.

## Quy trình chạy

1. Có thể chạy độc lập trong trang runner của extension, hoặc chạy từ tab `Thứ hạng keyword` trong Clickon Audit.
2. Nhập domain cần tìm, ví dụ `phelieuthienlong.com`.
3. Dán keyword, mỗi dòng một keyword.
4. Chọn số trang Google cần quét. Mặc định `10`, tương đương top 100 organic results.
5. Bấm `Chạy check rank`.
6. Extension sẽ quét lần lượt từng keyword, từng trang Google, tìm kết quả organic đầu tiên khớp domain/subdomain.
7. Có thể xuất CSV sau khi chạy xong.

## Tích hợp với Clickon Audit

Extension có content script trên `https://audit.clickon.vn/*`, `http://localhost/*`, `http://127.0.0.1/*`.

Tab `Thứ hạng keyword` trong website sẽ:

- Ping extension để kiểm tra đã cài chưa.
- Gửi danh sách keyword sang extension.
- Nhận từng kết quả từ extension rồi lưu qua API Laravel.
- Nếu bật auto captcha, tab web sẽ tạo/poll task 2captcha qua server để giữ API key và quản quota; extension chỉ nhận token để submit lại Google.

## Callback về hệ thống

Ô `Callback URL` là tùy chọn. Nếu nhập, extension sẽ POST kết quả cuối phiên về URL đó. Manifest hiện cấp quyền sẵn cho `https://audit.clickon.vn/*`, `http://localhost/*` và `http://127.0.0.1/*`. Nếu dùng domain callback khác, cần thêm host đó vào `manifest.json`.

Payload:

```json
{
  "domain": "example.com",
  "checkedAt": "2026-05-31T00:00:00.000Z",
  "stopped": false,
  "source": "chrome_extension",
  "search": {
    "googleHost": "https://www.google.com",
    "pages": 10,
    "hl": "vi",
    "gl": "vn"
  },
  "results": [
    {
      "keyword": "keyword",
      "domain": "example.com",
      "checkedAt": "2026-05-31T00:00:00.000Z",
      "status": "found",
      "rank": 7,
      "page": 1,
      "matchedUrl": "https://example.com/page",
      "title": "Example title",
      "error": ""
    }
  ]
}
```

## Ghi chú kỹ thuật

- Extension không hardcode cookie Google.
- Extension không tự vượt captcha.
- Nếu Google trả captcha/consent/unusual traffic, kết quả keyword sẽ có trạng thái `blocked`.
- Nên giữ delay vài giây giữa các request để giảm rủi ro bị chặn.
- Logic match domain là exact host hoặc subdomain, ví dụ `www.example.com` và `blog.example.com` khớp với `example.com`.
