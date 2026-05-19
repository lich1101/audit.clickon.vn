<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditPromptTemplate extends Model
{
    public const STEP_PRIMARY_KEYWORD = 'primary_keyword';
    public const STEP_CATEGORY_MAPPING = 'category_mapping';
    public const STEP_KEYWORD_CATEGORY_MAPPING = 'keyword_category_mapping';
    public const STEP_ONPAGE_AUDIT = 'onpage_audit';

    public const STEPS = [
        self::STEP_KEYWORD_CATEGORY_MAPPING,
        self::STEP_ONPAGE_AUDIT,
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
                'title' => 'Bước 2: Từ khóa SEO chính và danh mục',
                'developer_prompt' => implode("\n", [
                    'Bạn là chuyên gia SEO Google và kiến trúc silo website có 20 năm kinh nghiệm.',
                    'Bạn đang xử lý đúng 1 dòng dữ liệu URL mục tiêu trong batch audit.',
                    'Nhiệm vụ: đọc nội dung bài viết, title/meta/headings, danh sách danh mục và nội dung danh mục đã crawl để trả về đúng 1 từ khóa SEO chính và đúng 1 danh mục phù hợp nhất.',
                    'Từ khóa SEO chính phải là một cụm từ tiếng Việt tự nhiên, phản ánh search intent chính của URL, không chọn nhiều từ khóa.',
                    'Danh mục chỉ được chọn khi nội dung URL phù hợp tuyệt đối với chủ đề danh mục. Không gán theo cảm tính. Nếu không có danh mục đủ phù hợp, trả về chuỗi rỗng cho categoryName/categoryUrl.',
                    'Luôn ưu tiên sự phù hợp nội dung tuyệt đối giữa URL mục tiêu và danh mục, không ưu tiên chỉ vì tên trường/từ khóa xuất hiện thoáng qua.',
                    'Trả về JSON object đúng schema: {"primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string"}.',
                    'JSON only. No markdown.',
                ]),
                'user_prompt' => implode("\n\n", [
                    'URL: {{url}}',
                    'Dữ liệu bài viết đã crawl. Nếu content rỗng hoặc crawl lỗi, hãy dùng URL/title/headings còn lại để suy luận thận trọng:',
                    '{{page_json}}',
                    'Danh sách danh mục và nội dung danh mục đã crawl:',
                    '{{category_contexts_json}}',
                    'Yêu cầu đầu ra cho đúng 1 dòng này:',
                    '- primaryKeyword: chỉ 1 từ khóa SEO chính.',
                    '- categoryName/categoryUrl: đúng 1 danh mục phù hợp tuyệt đối hoặc chuỗi rỗng nếu không có danh mục đủ phù hợp.',
                    '- categoryMatchReason: giải thích ngắn vì sao danh mục đó phù hợp hoặc vì sao không có danh mục phù hợp.',
                ]),
                'is_active' => true,
            ],
            self::STEP_ONPAGE_AUDIT => [
                'title' => 'Bước 3: Audit Onpage SEO',
                'developer_prompt' => implode("\n", [
                    'Bạn là chuyên gia SEO Onpage Google có 20 năm kinh nghiệm, audit theo chuẩn Checklist Audit SEO của Clickon.',
                    'Bạn đang audit đúng 1 dòng URL mục tiêu sau khi đã có từ khóa SEO chính và danh mục tương ứng.',
                    'Nhiệm vụ: đọc nội dung bài viết, metadata, heading, metrics, từ khóa chính, danh mục và checklist AuditSEO để chấm điểm từng tiêu chí và đề xuất hướng xử lý.',
                    'Chấm điểm bắt buộc theo checklist trong user prompt:',
                    '- Nhóm I Kỹ thuật SEO: tối đa 24 điểm (STT 1–19).',
                    '- Nhóm II Nội dung & chuyên môn: tối đa 6 điểm (STT 20–25).',
                    '- Mỗi tiêu chí: đạt = full điểm, không đạt = 0 (tiêu chí 0,5đ chỉ chấm 0 hoặc 0,5).',
                    '- Nếu tiêu chí không kiểm chứng được từ dữ liệu crawl, chấm 0 và ghi rõ "không kiểm chứng được"; tuyệt đối không bịa search volume, trùng lặp nội dung hay dữ liệu đối thủ.',
                    '- auditScore (0–100) = làm tròn((điểm_kỹ_thuật + điểm_nội_dung) / 30 × 100).',
                    'Xác định hướng xử lý theo ma trận checklist:',
                    '- Viết lại: 0–8 điểm kỹ thuật SEO và 0–1 điểm nội dung.',
                    '- Audit Content: 9–18 điểm kỹ thuật SEO và 2–3 điểm nội dung.',
                    '- Giữ nguyên: 19–23 điểm kỹ thuật SEO và 4–6 điểm nội dung.',
                    'Ưu tiên Redirect nếu vi phạm: 0đ tiêu chí 19 (trùng lặp nội dung), 0đ tiêu chí 5 (không có volume), 0đ tiêu chí 6 (cannibalization — nhiều bài cùng từ khóa).',
                    'Đầu ra phải khớp bảng báo cáo Excel: URL mục tiêu, Từ khóa SEO chính, Danh mục, URL danh mục, Điểm phân tích Audit, Đề xuất audit, Định hướng chỉnh sửa nội dung theo Audit.',
                    'auditFindings: mảng 4–8 string. Bắt buộc có 2 dòng đầu: "Điểm kỹ thuật SEO: X/24" và "Điểm nội dung: Y/6". Các dòng sau liệt kê tiêu chí không đạt hoặc điểm mạnh nổi bật (kèm STT).',
                    'auditRecommendations: mảng 4–8 string hành động cụ thể, ưu tiên các tiêu chí mất nhiều điểm nhất.',
                    'contentRevisionDirection: string 3–5 câu. Câu đầu bắt buộc bắt đầu bằng "Hướng xử lý: Viết lại", "Hướng xử lý: Audit Content", "Hướng xử lý: Giữ nguyên" hoặc "Hướng xử lý: Redirect"; sau đó nêu lý do và các bước chỉnh sửa thực tế.',
                    'Trả về JSON object đúng schema: {"auditScore":number,"auditFindings":["string"],"auditRecommendations":["string"],"contentRevisionDirection":"string"}.',
                    'JSON only. No markdown.',
                ]),
                'user_prompt' => implode("\n\n", [
                    'URL: {{url}}',
                    'Từ khóa SEO chính: {{primary_keyword}}',
                    'Danh mục đã gán:',
                    '{{category_json}}',
                    'Dữ liệu trang:',
                    '{{page_json}}',
                    'Checklist audit:',
                    '{{checklist}}',
                    'Hãy audit Onpage SEO cho đúng URL này và trả về dữ liệu sẵn sàng đưa vào Excel.',
                ]),
                'is_active' => true,
            ],
        ];
    }
}
