<?php
/** Configuration transfer UI; shared services own validation and persistence. */
namespace ACF_VF\App;
defined('ABSPATH') || exit;

final class ConfigurationPage {
    public static function boot(): void {
        add_action('admin_menu', static function (): void {
            add_submenu_page('validated-field-workspace', 'Configuration transfer', 'Configuration transfer', 'manage_options', 'validated-field-configuration', [__CLASS__, 'render']);
        }, 20);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if ($hook !== 'validated-field_page_validated-field-configuration') { return; }
            wp_enqueue_style('acf-vf-settings', plugins_url('includes/app/assets/settings.css', ACF_VF_PLUGIN_FILE), [], (string) filemtime(__DIR__ . '/assets/settings.css'));
        });
        add_action('admin_post_acf_vf_configuration_export', [__CLASS__, 'download']);
    }

    public static function download(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Administrator access required.', 'validated-field-for-acf'), '', ['response'=>403]); }
        check_admin_referer('vf_configuration_export');
        $types = ($_POST['vf_export_kind'] ?? '') === 'client_types';
        $result = $types ? Admin::dispatch('client_types', []) : Migrations::export();
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>403]); }
        nocache_headers();
        header('Content-Type: ' . ($types ? 'text/plain; charset=utf-8' : 'application/json; charset=utf-8'));
        header('Content-Disposition: attachment; filename="' . ($types ? 'validated-field.d.ts' : 'vf-configuration.json') . '"');
        echo $types ? $result['content'] : wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Handle a review/apply POST without exposing Pro implementation in Free. */
    public static function submit(array $post, array $files = []): array {
        $state = ['source'=>'', 'plan'=>null, 'error'=>'', 'applied'=>false];
        if (!current_user_can('manage_options')) { $state['error']='Administrator access required.'; return $state; }
        if (!is_string($post['vf_transfer_nonce'] ?? null) || !wp_verify_nonce($post['vf_transfer_nonce'], 'vf_configuration_transfer')) { $state['error']='Your session could not be verified. Reload the page and try again.'; return $state; }
        if (!Extensions::has('configuration.apply')) { $state['error']='Install and activate Validated Field Pro to import configuration.'; return $state; }
        $action = $post['vf_transfer_action'] ?? '';
        if (!in_array($action, ['review','apply','edit'], true)) { $state['error']='Choose a transfer action.'; return $state; }
        $source = isset($post['vf_source']) && is_string($post['vf_source']) ? wp_unslash($post['vf_source']) : '';
        if (isset($files['vf_configuration_file'])) {
            $file = $files['vf_configuration_file'];
            if (!is_array($file) || !is_int($file['error'] ?? null)) { $state['error']='Choose a configuration JSON file.'; return $state; }
            if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($action !== 'review' || $source !== '' || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 1048576) { $state['error']='Choose one JSON file up to 1 MB, or paste its contents.'; return $state; }
                $source = file_get_contents($file['tmp_name']);
            }
        }
        if (!is_string($source) || strlen($source) > 1048576) { $state['error']='Configuration must be no larger than 1 MB.'; return $state; }
        $state['source'] = $source;
        if ($action === 'edit') { return $state; }
        if (!is_object(json_decode($source, false, Rest::JSON_DEPTH))) { $state['error']='Supply a valid configuration JSON object.'; return $state; }
        $definition = json_decode($source, true, Rest::JSON_DEPTH);
        if (!is_array($definition) || !Rest::bounded($definition)) { $state['error']='Configuration exceeds the supported size or nesting limits.'; return $state; }
        if ($action === 'apply') {
            if (($post['vf_confirm'] ?? '') !== '1' || !is_string($post['vf_plan_hash'] ?? null)) { $state['error']='Confirm the reviewed changes before applying.'; return $state; }
            $result = Admin::dispatch('configuration_apply', ['definition'=>$definition, 'plan_hash'=>$post['vf_plan_hash'], 'confirm'=>true]);
        } else {
            $result = Admin::dispatch('configuration_preflight', ['definition'=>$definition]);
        }
        if (is_wp_error($result)) { $state['error']=$result->get_error_message(); return $state; }
        if ($action === 'apply') { $state['applied']=true; $state['source']=''; }
        else { $state['plan']=$result; }
        return $state;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Administrator access required.', 'validated-field-for-acf'), '', ['response'=>403]); }
        $state = isset($_POST['vf_transfer_action']) ? self::submit($_POST, $_FILES) : ['source'=>'','plan'=>null,'error'=>'','applied'=>false];
        $configuration = Migrations::export();
        $pro = Extensions::has('configuration.apply');
        $page = admin_url('admin.php?page=validated-field-configuration');
        ?>
        <div class="wrap vf-settings vf-transfer">
            <header class="vf-settings-header"><div><p class="vf-settings-eyebrow">VALIDATED FIELD</p><h1>Configuration transfer</h1><p>Move your object definitions between sites, or keep a copy in version control.</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=validated-field-workspace')); ?>">Back to workspace</a></header>
            <?php if ($state['error'] !== '') { ?><div class="notice notice-error" role="alert"><p><?php echo esc_html($state['error']); ?></p></div><?php } ?>
            <?php if ($state['applied']) { ?><div class="notice notice-success" role="status"><p>Configuration applied. Your existing records have been preserved.</p></div><?php } ?>
            <div class="vf-settings-grid">
                <section class="vf-settings-card"><h2>Export configuration</h2><p>Download object definitions, fields, validation rules and access settings as a JSON file. Record values and license credentials are not included.</p>
                    <?php if ($configuration['objects']) { ?><ul class="vf-transfer-objects"><?php foreach ($configuration['objects'] as $slug=>$object) { ?><li><strong><?php echo esc_html($object['label'] ?? $slug); ?></strong><span><?php echo esc_html(sprintf(_n('%s field','%s fields',count($object['fields'] ?? []),'validated-field-for-acf'),number_format_i18n(count($object['fields'] ?? [])))); ?></span></li><?php } ?></ul><?php } else { ?><p class="vf-settings-muted">No managed objects yet. Create an object in the workspace to include it in your export.</p><?php } ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="acf_vf_configuration_export"><?php wp_nonce_field('vf_configuration_export'); ?><button class="button button-primary" type="submit">Download configuration</button><?php if (Extensions::has('headless.types')) { ?> <button class="button" type="submit" name="vf_export_kind" value="client_types">Download client types</button><?php } ?></form>
                </section>
                <section class="vf-settings-card"><h2>Import configuration</h2>
                    <?php if (!$pro) { ?><h3>Import with Pro</h3><p>Validated Field Pro lets you review proposed changes before applying an exported configuration. Exporting is available in Free.</p><a class="button" href="<?php echo esc_url(ACF_VF_PRO_UPGRADE_URL); ?>">Learn about Pro</a>
                    <?php } elseif ($state['plan']) { ?><p>Your import is ready for review below. Check the changes before applying them.</p>
                    <?php } else { ?><p>Choose an exported JSON file up to 1 MB, or paste its contents. Reviewing changes does not save them.</p>
                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($page); ?>">
                            <?php wp_nonce_field('vf_configuration_transfer','vf_transfer_nonce'); ?><input type="hidden" name="vf_transfer_action" value="review">
                            <p><label for="vf-configuration-file">Configuration file</label><input id="vf-configuration-file" type="file" name="vf_configuration_file" accept=".json,application/json"></p>
                            <details <?php if ($state['source'] !== '') { echo 'open'; } ?>><summary>Paste configuration JSON instead</summary><p><label for="vf-configuration-source" class="screen-reader-text">Configuration JSON</label><textarea id="vf-configuration-source" name="vf_source" rows="12" spellcheck="false"><?php echo esc_textarea($state['source']); ?></textarea></p></details>
                            <p><button class="button button-primary" type="submit">Review changes</button></p>
                        </form>
                    <?php } ?>
                </section>
                <?php if ($state['plan']) { $plan=$state['plan']; ?>
                    <section class="vf-settings-card vf-transfer-review"><h2>Review changes</h2><p>Review applies to this exact configuration. Existing records remain in WordPress.</p>
                        <div class="vf-transfer-counts"><?php foreach (['added'=>'Objects added','changed'=>'Objects changed','removed'=>'Objects removed'] as $key=>$label) { ?><div><strong><?php echo esc_html(number_format_i18n(count($plan['diff'][$key]))); ?></strong><span><?php echo esc_html($label); ?></span></div><?php } ?></div>
                        <?php foreach (['added'=>'Add','changed'=>'Change','removed'=>'Remove'] as $key=>$label) { foreach ($plan['diff'][$key] as $item) { $slug=is_array($item)?$item['object']:$item; ?><p><strong><?php echo esc_html($label); ?></strong> <code><?php echo esc_html($slug); ?></code></p><?php } } ?>
                        <?php foreach (array_merge($plan['warnings'],$plan['blocked']) as $warning) { ?><p class="vf-transfer-warning"><?php echo esc_html($warning); ?></p><?php } ?>
                        <details><summary>View detailed changes</summary><pre class="vf-transfer-diff"><?php echo esc_html(wp_json_encode($plan['diff'],JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></details>
                        <form method="post" action="<?php echo esc_url($page); ?>">
                            <?php wp_nonce_field('vf_configuration_transfer','vf_transfer_nonce'); ?><input type="hidden" name="vf_plan_hash" value="<?php echo esc_attr($plan['plan_hash']); ?>"><textarea name="vf_source" hidden><?php echo esc_textarea($state['source']); ?></textarea>
                            <?php if ($plan['applicable']) { ?><p><label><input type="checkbox" name="vf_confirm" value="1" required> I have reviewed these changes and want to apply them.</label></p><button type="submit" name="vf_transfer_action" value="apply" class="button button-primary">Apply reviewed changes</button><?php } else { ?><p>Resolve the blocked changes before importing this configuration.</p><?php } ?>
                            <button type="submit" name="vf_transfer_action" value="edit" class="button" formnovalidate>Edit import</button>
                        </form>
                    </section>
                <?php } ?>
            </div>
        </div>
        <?php
    }
}
