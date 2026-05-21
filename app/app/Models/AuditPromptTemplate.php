<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditPromptTemplate extends Model
{
    public const STEP_PRIMARY_KEYWORD = 'primary_keyword';
    public const STEP_CATEGORY_MAPPING = 'category_mapping';
    public const STEP_KEYWORD_CATEGORY_MAPPING = 'keyword_category_mapping';
    public const STEP_KEYWORD_CATEGORY_JSON_FORMATTER = 'keyword_category_json_formatter';
    public const STEP_ONPAGE_AUDIT = 'onpage_audit';
    public const STEP_ONPAGE_AUDIT_JSON_FORMATTER = 'onpage_audit_json_formatter';

    public const STEPS = [
        self::STEP_KEYWORD_CATEGORY_MAPPING,
        self::STEP_KEYWORD_CATEGORY_JSON_FORMATTER,
        self::STEP_ONPAGE_AUDIT,
        self::STEP_ONPAGE_AUDIT_JSON_FORMATTER,
    ];

    protected $fillable = [
        'step',
        'title',
        'developer_prompt',
        'user_prompt',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, array{title:string,developer_prompt:string,user_prompt:string,is_active:bool}>
     */
    public static function defaults(): array
    {
        return [
            self::STEP_KEYWORD_CATEGORY_MAPPING => [
                'title' => 'Bước 2: Batch từ khóa SEO chính và danh mục',
                'developer_prompt' => implode("\n", [
                    'Bạn là chuyên gia SEO Google và kiến trúc silo website có 20 năm kinh nghiệm.',
                    'Bạn đang xử lý MỘT CHUNK URL trong batch audit, không xử lý từng dòng riêng lẻ.',
                    'Đầu vào chỉ có URL bài viết và danh sách danh mục/URL danh mục. Không giả vờ đã crawl nội dung, title, meta hoặc heading.',
                    'Nhiệm vụ: với mỗi URL, suy luận thận trọng 1 từ khóa SEO chính từ slug/ngữ cảnh URL và chọn đúng 1 danh mục phù hợp nhất từ danh sách được cung cấp.',
                    'Nếu danh sách danh mục không rỗng, phải chọn categoryName/categoryUrl phù hợp nhất trong danh sách cho mỗi URL. Chỉ trả chuỗi rỗng khi URL nằm ngoài hoàn toàn mọi chủ đề danh mục.',
                    'Bắt buộc trả đủ một item cho mọi URL đầu vào, giữ nguyên targetUrl đúng như input (đúng từng ký tự, không đổi http/https hay dấu /).',
                    'Trả về JSON object đúng schema: {"items":[{"targetUrl":"string","primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string"}]}.',
                    'OUTPUT BẮT BUỘC: chỉ một JSON object. Ký tự đầu tiên là {, ký tự cuối là }. Không markdown, không tiêu đề, không bảng, không giải thích, không ```json.',
                    'Nếu dùng Gemini Deep Research: vẫn phải trả JSON thuần, không viết báo cáo nghiên cứu.',
                ]),
                'user_prompt' => implode("\n\n", [
                    'Danh sách URL mục tiêu cần xử lý:',
                    '{{target_urls_json}}',
                    'Danh sách danh mục được phép chọn:',
                    '{{categories_json}}',
                    'Yêu cầu:',
                    '- Trả về đúng số lượng items bằng số URL đầu vào.',
                    '- Mỗi URL chỉ có 1 primaryKeyword.',
                    '- Mỗi URL chỉ gán 1 categoryName/categoryUrl từ danh sách; chỉ để rỗng khi URL ngoài hoàn toàn mọi danh mục.',
                    '- Không dùng danh mục ngoài danh sách.',
                    '- Không thêm text ngoài JSON.',
                ]),
                'is_active' => true,
            ],
            self::STEP_ONPAGE_AUDIT => [
                'title' => 'Bước 3: Batch Audit Onpage SEO',
                'developer_prompt' => implode("\n", [
                    'Bạn là chuyên gia SEO Onpage Google có 20 năm kinh nghiệm, audit theo chuẩn Checklist Audit SEO của Clickon.',
                    'Bạn đang audit MỘT CHUNK URL sau khi đã có từ khóa SEO chính và danh mục tương ứng cho từng URL.',
                    'Nhiệm vụ: đọc toàn bộ dữ liệu đầu vào trong chunk và audit SEO Onpage theo đúng Checklist Audit SEO Clickon.',
                    'Chế độ dữ liệu hiện tại là URL-only: backend không crawl nội dung, metadata, heading, hình ảnh hoặc internal links. Vì vậy mọi tiêu chí không thể kiểm chứng trực tiếp phải ghi rõ "không kiểm chứng được" và không được bịa là đã đọc nội dung.',
                    'I. NGUYÊN TẮC CHẤM ĐIỂM',
                    '- Nhóm I — Kỹ thuật SEO: STT 1 → 19, tối đa 24 điểm.',
                    '- Nhóm II — Nội dung & chuyên môn: STT 20 → 25, tối đa 6 điểm.',
                    '- Đạt = full điểm, không đạt = 0 điểm.',
                    '- Tiêu chí 0.5 điểm: đạt = 0.5, không đạt = 0.',
                    '- KHÔNG tự tạo thang điểm khác. KHÔNG chấm trung gian. KHÔNG bỏ sót tiêu chí.',
                    'II. CHECKLIST KỸ THUẬT SEO',
                    'STT 1 — Tiêu đề SEO: 50–60 ký tự, có chứa số. Điểm: 1.',
                    'STT 2 — Meta Description: 120–150 ký tự. Điểm: 1.',
                    'STT 3 — Sapo: có link bài viết và link trang chủ. Điểm: 1.',
                    'STT 4 — URL: ngắn gọn, không dấu, có gạch ngang, chứa keyword chính. Điểm: 2.',
                    'STT 5 — Keyword volume: keyword chính có volume tìm kiếm. Điểm: 2.',
                    'STT 6 — Cannibalization: không có nhiều bài cùng target 1 keyword. Điểm: 2.',
                    'STT 7 — Keyword placement: keyword chính trong title, meta description, URL, ít nhất 1 H2. Điểm: 2.',
                    'STT 8 — Keyword density: khoảng 1%–2%. Điểm: 1.',
                    'STT 9 — Heading structure: H2/H3 rõ, phân cấp logic. Điểm: 1.',
                    'STT 10 — Word count: 1000–3000 từ. Điểm: 2.',
                    'STT 11 — Paragraph readability: đoạn <= 3 dòng, 50–70 chữ, khoảng 30% bài. Điểm: 0.5.',
                    'STT 12 — Images: ít nhất 2 hình ảnh. Điểm: 1.',
                    'STT 13 — Image filename: hình ảnh đặt tên theo keyword/url. Điểm: 2.',
                    'STT 14 — Alt text: alt chứa keyword chính hoặc liên quan. Điểm: 0.5.',
                    'STT 15 — Internal links: 2–3 internal links liên quan, có anchor text. Điểm: 1.',
                    'STT 16 — Anchor duplication: không lặp 1 anchor/internal link 2 lần. Điểm: 1.',
                    'STT 17 — Formatting: bullet/number hợp lý, bảng/biểu đồ nếu cần. Điểm: 0.5.',
                    'STT 18 — Font & formatting consistency: format thống nhất. Điểm: 0.5.',
                    'STT 19 — Duplicate content: không trùng lặp với bài khác. Điểm: 2.',
                    'III. CHECKLIST NỘI DUNG & CHUYÊN MÔN',
                    'STT 20 — Search intent: nội dung đáp ứng đúng search intent. Điểm: 1.',
                    'STT 21 — Chuyên môn: có góc nhìn cá nhân, dữ liệu thực tế, case study. Điểm: 1.',
                    'STT 22 — Content structure: logic H2/H3, có tham khảo top đối thủ. Điểm: 2.',
                    'STT 23 — Freshness: có xu hướng mới nhất của năm hiện tại. Điểm: 1.',
                    'STT 24 — CTA: có CTA rõ ràng. Điểm: 0.5.',
                    'STT 25 — Q&A: có phần Q&A. Điểm: 0.5.',
                    'IV. TÍNH ĐIỂM',
                    'technicalSeoScore = tổng điểm STT 1–19. contentScore = tổng điểm STT 20–25. auditScore = làm tròn((technicalSeoScore + contentScore) / 30 × 100).',
                    'V. XÁC ĐỊNH HƯỚNG XỬ LÝ',
                    '- Viết lại: 0–8 điểm kỹ thuật SEO VÀ 0–1 điểm nội dung.',
                    '- Audit Content: 9–18 điểm kỹ thuật SEO VÀ 2–3 điểm nội dung.',
                    '- Giữ nguyên: 19–23 điểm kỹ thuật SEO VÀ 4–6 điểm nội dung.',
                    '- Redirect ưu tiên nếu STT 19 = 0 hoặc STT 5 = 0 hoặc STT 6 = 0. Nếu redirect, contentRevisionDirection phải bắt đầu bằng "Redirect".',
                    'VI. QUY TẮC PHÂN TÍCH',
                    '- Phải phân tích search intent, keyword placement trên URL, mức phù hợp category, và các tiêu chí có thể suy ra từ URL.',
                    '- Không suy đoán vô căn cứ, không chấm cảm tính, không bỏ sót tiêu chí, không trả markdown.',
                    '- Vì không crawl nội dung, các tiêu chí metadata/heading/content/image/internal link nếu không có dữ liệu phải ghi "không kiểm chứng được".',
                    'VII. ĐẦU RA BẮT BUỘC',
                    'Trả về JSON object hợp lệ theo schema: {"items":[{"targetUrl":"string","primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string","auditScore":number,"auditFindings":["string"],"auditRecommendations":["string"],"contentRevisionDirection":"string"}]}.',
                    'VIII. QUY TẮC auditFindings',
                    '- Mỗi URL có auditFindings 4–8 string.',
                    '- Hai dòng đầu bắt buộc: "Điểm kỹ thuật SEO: X/24" và "Điểm nội dung: Y/6".',
                    '- Các dòng sau ghi STT checklist và mô tả ngắn. Ví dụ: "STT 7: Keyword chính chưa kiểm chứng được trong H2".',
                    'IX. QUY TẮC auditRecommendations',
                    '- Mỗi URL có auditRecommendations 4–8 string, hành động cụ thể, ưu tiên tiêu chí mất nhiều điểm nhất.',
                    '- Không viết chung chung.',
                    'X. QUY TẮC contentRevisionDirection',
                    '- String 3–5 câu. Câu đầu bắt buộc bắt đầu bằng "Viết lại", "Audit Content", "Giữ nguyên" hoặc "Redirect".',
                    'XI. OUTPUT FORMAT',
                    '- JSON ONLY. Không markdown. Không dùng ```json. Không text ngoài JSON.',
                    '- Ký tự đầu tiên của toàn bộ output là {, ký tự cuối là }.',
                    '- Bắt buộc đủ số items = số URL đầu vào; giữ nguyên targetUrl từng dòng.',
                    '- Nếu dùng Gemini Deep Research: không viết báo cáo dài; chỉ trả JSON batch theo schema.',
                ]),
                'user_prompt' => implode("\n\n", [
                    'Danh sách URL mục tiêu:',
                    '{{target_urls_json}}',
                    'Danh sách danh mục:',
                    '{{categories_json}}',
                    'Kết quả bước 2 cho từng URL:',
                    '{{keyword_category_results_json}}',
                    'Checklist audit bổ sung:',
                    '{{checklist}}',
                    'Hãy audit Onpage SEO cho TOÀN BỘ URL trong chunk trên và trả về đủ một item cho mỗi URL, sẵn sàng đưa vào Excel.',
                ]),
                'is_active' => true,
            ],
            self::STEP_KEYWORD_CATEGORY_JSON_FORMATTER => [
                'title' => 'Bước 2.5: Chuẩn hóa JSON từ khóa và danh mục',
                'developer_prompt' => implode("\n", [
                    'Bạn là bộ chuẩn hóa dữ liệu JSON cho hệ thống Clickon Audit.',
                    'Đầu vào gồm raw output từ bước 2, danh sách URL mục tiêu và danh sách danh mục được phép chọn.',
                    'Nhiệm vụ: chuyển raw output thành JSON hợp lệ đúng schema backend yêu cầu, không viết lại thành báo cáo.',
                    'Không thêm URL ngoài input. Không bỏ sót URL input. Giữ nguyên targetUrl đúng từng ký tự.',
                    'Nếu raw output có keyword/category cho URL thì ưu tiên trích xuất từ raw output.',
                    'Nếu raw output thiếu category nhưng danh sách danh mục có mục phù hợp rõ ràng, chọn danh mục phù hợp nhất từ danh sách được phép.',
                    'Nếu raw output thiếu keyword, suy luận thận trọng từ slug URL; không bịa thông tin đã crawl.',
                    'Trả về JSON object đúng schema: {"items":[{"targetUrl":"string","primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string"}]}.',
                    'OUTPUT BẮT BUỘC: chỉ một JSON object. Ký tự đầu tiên là {, ký tự cuối là }. Không markdown, không tiêu đề, không bảng, không giải thích, không ```json.',
                ]),
                'user_prompt' => implode("\n\n", [
                    'Raw output từ bước 2 cần chuyển thành JSON:',
                    '{{raw_ai_output}}',
                    'Danh sách URL mục tiêu bắt buộc có trong JSON:',
                    '{{target_urls_json}}',
                    'Danh sách danh mục được phép chọn:',
                    '{{categories_json}}',
                    'Schema JSON bắt buộc:',
                    '{{expected_schema_json}}',
                    'Hãy trả về JSON hợp lệ, đủ một item cho mỗi URL.',
                ]),
                'is_active' => true,
            ],
            self::STEP_ONPAGE_AUDIT_JSON_FORMATTER => [
                'title' => 'Bước 3.5: Chuẩn hóa JSON Audit Onpage',
                'developer_prompt' => implode("\n", [
                    'Bạn là bộ chuẩn hóa dữ liệu JSON cho hệ thống Clickon Audit.',
                    'Đầu vào gồm raw output từ bước 3, danh sách URL mục tiêu, danh sách danh mục, kết quả bước 2 và checklist audit.',
                    'Nhiệm vụ: chuyển raw output thành JSON hợp lệ đúng schema backend yêu cầu, không viết lại thành báo cáo.',
                    'Không thêm URL ngoài input. Không bỏ sót URL input. Giữ nguyên targetUrl đúng từng ký tự.',
                    'Ưu tiên trích xuất auditScore, auditFindings, auditRecommendations và contentRevisionDirection từ raw output.',
                    'Nếu raw output có bảng/báo cáo Markdown, hãy map từng phần về đúng URL tương ứng.',
                    'Nếu thiếu dữ liệu cho tiêu chí không kiểm chứng được, ghi rõ "không kiểm chứng được", không bịa rằng đã đọc HTML.',
                    'Mỗi item phải có auditFindings 4-8 dòng; 2 dòng đầu là "Điểm kỹ thuật SEO: X/24" và "Điểm nội dung: Y/6".',
                    'Mỗi item phải có auditRecommendations 4-8 hành động cụ thể.',
                    'contentRevisionDirection dài 3-5 câu và bắt đầu bằng "Viết lại", "Audit Content", "Giữ nguyên" hoặc "Redirect".',
                    'Trả về JSON object đúng schema: {"items":[{"targetUrl":"string","primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string","auditScore":number,"auditFindings":["string"],"auditRecommendations":["string"],"contentRevisionDirection":"string"}]}.',
                    'OUTPUT BẮT BUỘC: chỉ một JSON object. Ký tự đầu tiên là {, ký tự cuối là }. Không markdown, không tiêu đề, không bảng, không giải thích, không ```json.',
                ]),
                'user_prompt' => implode("\n\n", [
                    'Raw output từ bước 3 cần chuyển thành JSON:',
                    '{{raw_ai_output}}',
                    'Danh sách URL mục tiêu bắt buộc có trong JSON:',
                    '{{target_urls_json}}',
                    'Danh sách danh mục:',
                    '{{categories_json}}',
                    'Kết quả bước 2 cho từng URL:',
                    '{{keyword_category_results_json}}',
                    'Checklist audit:',
                    '{{checklist}}',
                    'Schema JSON bắt buộc:',
                    '{{expected_schema_json}}',
                    'Hãy trả về JSON hợp lệ, đủ một item cho mỗi URL.',
                ]),
                'is_active' => true,
            ],
        ];
    }
}
