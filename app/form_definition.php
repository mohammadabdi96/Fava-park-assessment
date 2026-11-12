<?php
/**
 * Definition of the assessment form «کاربرگ ارزش‌گذاری استراتژیک طرح».
 *
 * Texts and structure come from the form designed by the park. The numeric
 * parameters under 'defaults' (weights, thresholds, tier ceilings) can be changed
 * by the admin in «تنظیمات › امتیازدهی»; everything else is edited here.
 *
 * Criterion ids (tech-1 …) are stored with every submission: never renumber or
 * reuse them once the system is in use — add new ids instead.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

return [
    // Which judge fills which part
    'roles' => [
        'tech' => ['label' => 'داور فنی', 'short' => 'فنی', 'dims' => ['tech', 'team'], 'risks' => ['trl', 'risk_exec'], 'questions' => ['q1', 'q2', 'q3']],
        'biz' => ['label' => 'داور کسب‌وکاری', 'short' => 'کسب‌وکاری', 'dims' => ['market', 'finance'], 'risks' => ['risk_market'], 'questions' => ['q4', 'q5']],
    ],

    // Layer 2 – weighted scoring (each criterion 1..5)
    'dimensions' => [
        'tech' => [
            'label' => 'فنی و نوآوری', 'title' => 'بُعد فنی و نوآوری', 'role' => 'tech', 'color' => 'blue',
            'criteria' => [
                'tech-1' => 'سطح نوآوری',
                'tech-2' => 'دسترسی به زیرساخت مناسب',
                'tech-3' => 'مالکیت فکری (IP)',
                'tech-4' => 'مزیت رقابتی فنی',
                'tech-5' => 'پیچیدگی فنی',
            ],
        ],
        'market' => [
            'label' => 'بازار و مقیاس‌پذیری', 'title' => 'بُعد بازار و مقیاس‌پذیری', 'role' => 'biz', 'color' => 'emerald',
            'criteria' => [
                'market-1' => 'اندازه بازار هدف',
                'market-2' => 'کشش تقاضا',
                'market-3' => 'مقیاس‌پذیری',
                'market-4' => 'مزیت رقابتی تجاری',
                'market-5' => 'استراتژی ورود به بازار',
            ],
        ],
        'team' => [
            'label' => 'تیم و اجرا', 'title' => 'بُعد تیم و اجرا', 'role' => 'tech', 'color' => 'purple',
            'criteria' => [
                'team-1' => 'سابقه تیم',
                'team-2' => 'تکمیل بودن تخصص‌ها',
                'team-3' => 'توان اجرای پروژه',
                'team-4' => 'سابقه موفق در پروژه‌های قبلی',
                'team-5' => 'حضور فرد فنی اصلی',
            ],
        ],
        'finance' => [
            'label' => 'مالی و مدل درآمدی', 'title' => 'بُعد مالی و مدل درآمدی', 'role' => 'biz', 'color' => 'amber',
            'criteria' => [
                'finance-1' => 'شفافیت مدل درآمدی',
                'finance-2' => 'حاشیه سود',
                'finance-3' => 'نوآوری در مدل درآمدی',
                'finance-4' => 'وجود اعلام نیاز از مشتریان',
                'finance-5' => 'برآورد صحیح هزینه و زمان اجرای طرح',
            ],
        ],
    ],

    // Layer 1 – strategic classification (both judges)
    'scalability' => [
        'high' => ['بسیار بالا', 'پتانسیل توسعه در ۵ استان یا بیشتر، یا بازار بین‌المللی؛ پلتفرم/زیرساخت/فناوری پایه'],
        'medium' => ['متوسط', 'پتانسیل توسعه در ۲ تا ۴ استان؛ نرم‌افزار/خدمت قابل تکثیر با بومی‌سازی'],
        'low' => ['محدود', 'بازار یک استان/شهر؛ امکان تکثیر ندارد'],
    ],
    'priority' => [
        'high' => ['بسیار بالا', 'حوزه‌های اولویت‌دار ملی (AI، امنیت سایبری، زیرساخت دیجیتال، فناوری‌های نوظهور)'],
        'medium' => ['متوسط', 'حوزه‌های مهم استانی (کشاورزی هوشمند، سلامت دیجیتال، گردشگری فناورانه)'],
        'normal' => ['معمولی', 'خدمت/محصول عمومی بدون اولویت استراتژیک خاص'],
    ],

    // Layer 3 – risk
    'trl' => [
        1 => 'اصول پایه مشاهده شده',
        2 => 'مفهوم فناوری تدوین شده',
        3 => 'اثبات مفهومی آزمایشگاهی',
        4 => 'نمونه آزمایشگاهی',
        5 => 'اعتبارسنجی محیط شبه‌عملیاتی',
        6 => 'نمونه مهندسی، تست واقعی',
        7 => 'تست نمونه در محیط عملیاتی',
        8 => 'آماده‌سازی تولید',
        9 => 'استفاده کامل در دنیای واقعی',
    ],
    'risk_market' => [
        5 => 'مشتری واقعی و قرارداد فعال',
        3 => 'کشش اثبات‌شده بدون قرارداد',
        1 => 'بازار نامشخص یا رقابتی',
    ],
    'risk_exec' => [
        5 => 'تیم کامل با سابقه موفق مشابه',
        3 => 'تیم نسبتاً کامل، سابقه محدود',
        1 => 'تیم ناقص یا بدون سابقه مرتبط',
    ],

    // Analytical opinion (optional free text)
    'questions' => [
        'q1' => 'مزیت رقابتی فنی طرح و ارزش افزوده احتمالی',
        'q2' => 'امکان‌پذیری فنی و دستیابی به محصول در بازه ۱۲ ماهه',
        'q3' => 'وضعیت تأییدیه‌ها، مجوزها و استانداردهای الزامی',
        'q4' => 'وضعیت بازار و رقبا از منظر تجاری',
        'q5' => 'تحلیل مالی و توجیه اقتصادی طرح',
    ],

    // Layer 4 – staged payment
    'milestones' => [
        ['۱. آغاز پروژه', 'امضای قرارداد، برنامه اجرایی، تعیین مایلستون‌ها'],
        ['۲. اثبات پیشرفت', 'تحویل مایلستون فنی اول'],
        ['۳. تکمیل', 'تحویل محصول نهایی، گزارش اثرگذاری، اثبات KPI'],
    ],

    'decisions' => [
        'approve' => ['label' => 'تأیید', 'title' => 'طرح قابل تأیید', 'color' => 'green'],
        'conditional' => ['label' => 'تأیید مشروط', 'title' => 'طرح نیازمند اصلاح', 'color' => 'amber'],
        'reject' => ['label' => 'رد قطعی', 'title' => 'طرح غیرقابل دفاع', 'color' => 'red'],
    ],

    'commitment' => 'بدین‌وسیله اعلام می‌کنم که داوری طرح در حیطه تخصص علمی و فنی اینجانب بوده و از اجرای طرح منتفع یا متضرر نمی‌شوم؛ همچنین رعایت امانت‌داری، عدم رقابت و عدم افشای اطلاعات را متعهد می‌شوم.',

    // Numeric parameters – the admin can override these in the settings
    'defaults' => [
        'tiers' => [
            'A' => ['label' => 'سطح الف — استراتژیک ملی/بین‌المللی', 'short' => 'الف', 'ceiling' => 30],
            'B' => ['label' => 'سطح ب — رشد ملی/منطقه‌ای', 'short' => 'ب', 'ceiling' => 15],
            'C' => ['label' => 'سطح ج — توسعه‌ای استانی/محلی', 'short' => 'ج', 'ceiling' => 8],
        ],
        'thresholds' => ['reject_below' => 40, 'conditional_below' => 60],
        'weights' => ['tech' => 0.30, 'market' => 0.25, 'team' => 0.20, 'finance' => 0.25],
        'risk' => ['base' => 0.7, 'step' => 0.15], // coefficient = base + (average risk − 1) × step  → 0.7 … 1.3
        'cap_amount' => true,                       // support never exceeds min(requested, tier ceiling)
        'payment' => [30, 40, 30],                  // percentages of the three stages
    ],
];
