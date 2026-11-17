<?php
/**
 * Thrive Leads wiring (demo).
 *
 * In production this is a short file inside Thrive Leads. It only listens to
 * hooks Thrive Leads already fires, so nothing about how Leads works changes.
 *
 *   Built        first campaign created
 *   Live         first campaign seen by a real visitor (not an admin, not a bot)
 *   First value  leads captured from at least 2 different people
 *
 * Notes from the code:
 * - Campaigns are saved as "publish" the moment they're created, so post status
 *   can't tell us when a campaign went live. A real impression can.
 * - The impression hook skips bots but not logged-in admins, and the conversion
 *   hook skips neither, so this file filters admins itself.
 * - Visitor emails never leave the site. Only a hash is kept, locally, and only
 *   until two different people have signed up.
 */

if (!defined('TVE_LEADS_POST_GROUP_TYPE')) {
    define('TVE_LEADS_POST_GROUP_TYPE', 'tve_lead_group');
}

/**
 * Reads the local Thrive Leads tables. The simulator swaps in a fake by
 * defining this class before including the file. The queries are illustrative;
 * engineering should confirm the exact columns (for example how display status
 * is stored) before using them.
 */
if (!class_exists('TL_Success_Data')) {
    class TL_Success_Data
    {
        public static function activated_at(): ?int
        {
            $at = get_option('tve_leads_install_date');
            return $at ? (int) strtotime((string) $at) : null;
        }

        public static function first_campaign_date(): ?string
        {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash'",
                TVE_LEADS_POST_GROUP_TYPE
            )) ?: null;
        }

        public static function first_impression_date(): ?string
        {
            global $wpdb;
            return $wpdb->get_var("SELECT MIN(date) FROM {$wpdb->prefix}tve_leads_form_summary WHERE impression_count > 0") ?: null;
        }

        /** Date of the second distinct lead, i.e. when "First value" was reached. */
        public static function second_lead_date(): ?string
        {
            global $wpdb;
            return $wpdb->get_var(
                "SELECT MIN(first_seen) FROM (
                    SELECT MIN(date) AS first_seen FROM {$wpdb->prefix}tve_leads_contacts
                    WHERE email IS NOT NULL AND email <> '' GROUP BY email ORDER BY first_seen LIMIT 1 OFFSET 1
                 ) t"
            ) ?: null;
        }

        public static function counts(): array
        {
            global $wpdb;
            $week = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
            return [
                'campaigns' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", TVE_LEADS_POST_GROUP_TYPE)),
                'campaigns_displaying' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'display_status' AND meta_value IN ('yes', 'custom')"),
                'impressions_7d' => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(impression_count), 0) FROM {$wpdb->prefix}tve_leads_form_summary WHERE date >= %s", $week)),
                'leads_7d' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}tve_leads_contacts WHERE date >= %s AND email <> ''", $week)),
                'email_services_connected' => self::email_services_connected(),
            ];
        }

        /** The built-in "email" and "wordpress" options always show as connected, so they don't count. */
        private static function email_services_connected(): int
        {
            if (!class_exists('Thrive_Dash_List_Manager')) {
                return 0;
            }
            $apis = Thrive_Dash_List_Manager::get_available_apis(true, ['include_types' => ['email']]);
            return count(array_diff(array_keys((array) $apis), ['email', 'wordpress']));
        }
    }
}

Thrive_Success_Client::register_product('tl', [
    'version' => defined('TVE_LEADS_VERSION') ? TVE_LEADS_VERSION : '11.2',
    'milestones' => ['first_campaign_created', 'first_campaign_live', 'first_leads'],
    'state' => static function (): array {
        $counts = TL_Success_Data::counts();
        return [
            'activated_at' => TL_Success_Data::activated_at(),
            'milestones' => array_filter([
                'first_campaign_created' => TL_Success_Data::first_campaign_date(),
                'first_campaign_live' => TL_Success_Data::first_impression_date(),
                'first_leads' => TL_Success_Data::second_lead_date(),
            ]),
            'metrics' => $counts,
            'value' => $counts['leads_7d'],
        ];
    },
]);

// Lifecycle. WordPress fires these actions itself on activate / deactivate.
add_action('activate_thrive-leads/thrive-leads.php', static function () {
    Thrive_Success_Client::track('tl', 'plugin_activated', ['version' => defined('TVE_LEADS_VERSION') ? TVE_LEADS_VERSION : '11.2']);
});
add_action('deactivate_thrive-leads/thrive-leads.php', static function () {
    Thrive_Success_Client::track('tl', 'plugin_deactivated');
    Thrive_Success_Client::flush(); // the outbox would never be sent after this
});

/** Called from Thrive Leads' uninstall routine. */
function thrive_success_tl_uninstalled(): void
{
    Thrive_Success_Client::track('tl', 'plugin_uninstalled');
    Thrive_Success_Client::flush();
}

// Built: the first lead group is created.
add_action('wp_insert_post', static function ($post_id, $post, $update) {
    if ($update || !is_object($post) || $post->post_type !== TVE_LEADS_POST_GROUP_TYPE) {
        return;
    }
    Thrive_Success_Client::track('tl', 'first_campaign_created', ['campaign_type' => 'lead_group']);
}, 10, 3);

// Live: the first time a real visitor sees a campaign.
add_action('tve_leads_form_impression', static function ($group, $form_type, $variation, $active_test_id, $current_screen) {
    if (current_user_can('manage_options')) {
        return; // the owner previewing their own site
    }
    Thrive_Success_Client::track('tl', 'first_campaign_live', [
        'form_type' => is_object($form_type) ? (string) ($form_type->post_title ?? '') : '',
    ]);
}, 10, 5);

// First value: email sign-ups from at least 2 different people.
add_action('tve_leads_form_conversion', static function ($main, $form_type, $variation, $active_test_id, $data, $current_screen) {
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '' || current_user_can('manage_options')) {
        return; // button-click conversions and the owner testing don't count
    }
    $seen = get_option('thrive_success_tl_seen', []);
    if ($seen === 'done') {
        return;
    }
    $seen = (array) $seen;
    $seen[hash('sha256', $email)] = true; // stays on this site
    if (count($seen) < 2) {
        update_option('thrive_success_tl_seen', $seen, false);
        return;
    }
    update_option('thrive_success_tl_seen', 'done', false); // the hashes are discarded
    Thrive_Success_Client::track('tl', 'first_leads');
}, 10, 6);
