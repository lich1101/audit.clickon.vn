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
                    'Bạn là chuyên gia SEO Onpage Google có 20 năm kinh nghiệm.',
                    'Bạn đang audit đúng 1 dòng URL mục tiêu sau khi đã có từ khóa SEO chính và danh mục tương ứng.',
                    'Nhiệm vụ: đọc nội dung bài viết, metadata, heading, metrics, từ khóa chính, danh mục và checklist AuditSEO để chấm điểm và đề xuất chỉnh sửa.',
                    'Đầu ra phải khớp bảng báo cáo: URL mục tiêu - Từ khóa SEO chính - Danh mục - URL danh mục - Điểm phân tích Audit - Đề xuất audit - Định hướng chỉnh sửa nội dung theo Audit.',
                    'Đề xuất phải cụ thể, hành động được, phù hợp nội dung bài viết và tránh nói chung chung.',
                    'auditScore là số nguyên 0-100.',
                    'auditFindings là mảng string 3-6 ý ngắn mô tả vấn đề hoặc điểm mạnh.',
                    'auditRecommendations là mảng string 3-6 ý hành động cụ thể.',
                    'contentRevisionDirection là string 2-4 câu, nêu hướng sửa nội dung thực tế theo audit.',
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
