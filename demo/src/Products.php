<?php

/**
 * Product configuration: how each product's own moments map onto the shared
 * five-stage ladder, plus the support playbook for each stage.
 */
final class Products
{
    public const STAGES = [
        1 => 'Activated',
        2 => 'Built',
        3 => 'Live',
        4 => 'First value',
        5 => 'Adopted',
    ];

    /** The one transition the pilot is pre-registered on. */
    public const PILOT = ['product' => 'tl', 'from_stage' => 3];

    public static function all(): array
    {
        return [
            'tl' => [
                'code' => 'tl',
                'name' => 'Thrive Leads',
                'wave' => 'Pilot',
                'object' => 'campaign',
                'milestones' => [
                    'activated' => 1,
                    'first_campaign_created' => 2,
                    'first_campaign_live' => 3,
                    'first_leads' => 4,
                    'adopted' => 5,
                ],
                'labels' => [
                    1 => 'Activated',
                    2 => 'First campaign created',
                    3 => 'First campaign live',
                    4 => 'First real leads',
                    5 => 'Leads every week',
                ],
                'value_metric' => 'leads_7d',
                'value_label' => 'new leads',
                'thresholds' => [1 => 3, 2 => 7, 3 => 14, 4 => 30],
            ],
            'ta' => [
                'code' => 'ta',
                'name' => 'Thrive Apprentice',
                'wave' => 'Wave 2',
                'object' => 'course',
                'milestones' => [
                    'activated' => 1,
                    'first_course_created' => 2,
                    'first_course_published' => 3,
                    'first_enrollment' => 4,
                    'adopted' => 5,
                ],
                'labels' => [
                    1 => 'Activated',
                    2 => 'First course with a lesson',
                    3 => 'First course published',
                    4 => 'First student enrolled',
                    5 => 'Enrollments every week',
                ],
                'value_metric' => 'enrollments_7d',
                'value_label' => 'enrollments',
                'thresholds' => [1 => 5, 2 => 14, 3 => 21, 4 => 30],
            ],
            'tqb' => [
                'code' => 'tqb',
                'name' => 'Thrive Quiz Builder',
                'wave' => 'Wave 2',
                'object' => 'quiz',
                'milestones' => [
                    'activated' => 1,
                    'first_quiz_ready' => 2,
                    'first_quiz_seen' => 3,
                    'first_quiz_taken' => 4,
                    'adopted' => 5,
                ],
                'labels' => [
                    1 => 'Activated',
                    2 => 'First complete quiz',
                    3 => 'First quiz seen by a visitor',
                    4 => 'First quiz completed',
                    5 => 'Completions every week',
                ],
                'value_metric' => 'completions_7d',
                'value_label' => 'quiz completions',
                'thresholds' => [1 => 3, 2 => 7, 3 => 14, 4 => 30],
            ],
        ];
    }

    public static function get(string $code): array
    {
        $all = self::all();
        if (!isset($all[$code])) {
            throw new InvalidArgumentException("Unknown product: $code");
        }
        return $all[$code];
    }

    public static function exists(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    public static function stageFor(string $product, string $milestone): ?int
    {
        return self::get($product)['milestones'][$milestone] ?? null;
    }

    /**
     * What support should do next, by product and stage. Copy is a starting point
     * the support person edits, not a mail merge that goes out untouched.
     */
    public static function playbook(string $product, int $stage, string $health, array $ctx): array
    {
        $first = $ctx['first_name'] ?: 'there';
        $site = $ctx['site_name'] ?: 'your site';
        $m = $ctx['metrics'];

        if ($health === 'slipping') {
            return self::play(
                'Find out why the results stopped',
                "They were getting results, then nothing for 30+ days. The usual causes are a campaign that got switched off or an email service that disconnected.",
                "Hi $first,\n\nI noticed $site was getting new leads from Thrive Leads, and then they stopped about a month ago. That usually means a campaign got switched off or the email service lost its connection.\n\nWant me to take a quick look with you? It's normally a 5-minute fix.\n\nThanks,"
            );
        }

        if ($product === 'tl') {
            switch ($stage) {
                case 1:
                    return self::play(
                        'Help them build a first campaign',
                        'Thrive Leads is active but nothing has been built. A template gets most people to a working popup in minutes.',
                        "Hi $first,\n\nThanks for setting up Thrive Leads on $site. The fastest way to get your first signups is a simple popup or slide-in from one of the ready-made templates. It takes about 5 minutes.\n\nWould a short step-by-step guide help, or would you rather I walk you through it?\n\nThanks,"
                    );
                case 2:
                    return self::play(
                        'Get the campaign in front of real visitors',
                        ($m['campaigns_displaying'] ?? 0) > 0
                            ? 'A campaign is set to display, but no real visitor has seen it yet. The display rules are probably too narrow.'
                            : 'A campaign exists but nothing is set to display yet.',
                        "Hi $first,\n\nYou've built a campaign in Thrive Leads on $site, but it hasn't been shown to a visitor yet. Most of the time that's the display settings: which pages it shows on, or a trigger like exit intent that's hard to hit.\n\nIf you tell me where you want it to appear, I can suggest the right settings.\n\nThanks,"
                    );
                case 3:
                    $views = (int) ($m['impressions_7d'] ?? 0);
                    $seen = $views > 0 ? " ($views times in the last week)" : '';
                    if ((int) ($m['email_services_connected'] ?? 1) === 0) {
                        return self::play(
                            'Connect an email service',
                            'Live, but no email service is connected, so there is nowhere useful for leads to go.',
                            "Hi $first,\n\nYour campaign on $site is live and visitors are seeing it$seen. One thing stands out: no email service is connected yet, so new sign-ups don't reach a mailing list you can follow up with.\n\nConnecting the email tool you already use takes a couple of minutes, and I'm happy to walk you through it.\n\nThanks,"
                        );
                    }
                    return self::play(
                        'Turn views into leads',
                        "Live and being seen ($views views in the last 7 days), but no real leads yet. Usually the offer or the form.",
                        "Hi $first,\n\nYour campaign on $site is live and visitors are seeing it$seen, but it hasn't captured leads yet.\n\nThe two changes that usually help most are a clearer reason to sign up (a checklist, a discount, a free guide) and a shorter form. Happy to look at it with you if that's useful.\n\nThanks,"
                    );
                case 4:
                    return self::play(
                        'Help them make it a habit',
                        'They got their first real leads. A second campaign type or a simple A/B test is the usual next step.',
                        "Hi $first,\n\nNice work, $site is collecting leads with Thrive Leads. A lot of customers get the next jump by adding a second format (for example a slide-in on blog posts) or testing two versions of the headline.\n\nWant a couple of ideas that fit your site?\n\nThanks,"
                    );
                default:
                    return self::play(
                        'Nothing to fix',
                        'Healthy and getting leads every week. A good candidate for a review request or a case study, not a nudge.',
                        "Hi $first,\n\n$site has been getting new leads every week with Thrive Leads, which is great to see. Would you be open to sharing a short review of how it's going?\n\nThanks,"
                    );
            }
        }

        $p = self::get($product);
        $next = $p['labels'][min($stage + 1, 5)] ?? '';
        return self::play(
            $stage >= 5 ? 'Nothing to fix' : "Help them reach: $next",
            "Playbook for {$p['name']} is a placeholder until the product owner signs off its stages.",
            "Hi $first,\n\nI wanted to check in on how {$p['name']} is going on $site. Is there anything I can help you get set up?\n\nThanks,"
        );
    }

    private static function play(string $title, string $why, string $message): array
    {
        return ['title' => $title, 'why' => $why, 'message' => $message];
    }
}
