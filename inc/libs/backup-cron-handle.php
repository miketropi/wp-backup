<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * @author: Mike Tropi
 * @version: 1.0.0
 * @date: 2025-07-02
 * @description: Backup Cron Handler Class
 * @support: https://github.com/miketropi/worry-proof-backup
 * @license: GPL-2.0+
 * @copyright: (c) 2025 Mike Tropi
 */

class WORRPB_Cron_Handler {
  protected $type = 'weekly';
  protected $backup_types = ['database', 'plugin', 'theme', 'uploads'];
  protected $steps = [];
  protected $period_key;
  protected $config = [];

  public function __construct() {
    add_action('init', [$this, 'init_handler'], 20);
  }

  public function init_handler() {
    // get backup schedule config
    $config = worrprba_get_backup_schedule_config();
    $this->config = $config;

    // check if config is wp error
    if (is_wp_error($config)) {
      worrprba_log("😵 BACKUP SCHEDULE CONFIG ERROR: " . $config->get_error_message());
      return;
    }

    if($config === false) {
      // error_log("👋 BACKUP SCHEDULE CONFIG NOT FOUND");
      return;
    }

    $enabled = $config['enabled'] ?? false;

    // check if enabled is true
    if($enabled != true) { return; }

    // get frequency & types
    $this->type = $config['frequency'] ?? 'weekly';
    $this->backup_types = $config['types'] ?? ['database', 'plugin', 'theme', 'uploads'];

    // ✅ start in init
    $this->period_key = worrprba_get_period_key($this->type);
    $this->register_steps();
    $this->handle_cron();
  }

  protected function register_steps() {
    $step = 0;

    $this->steps["step__{$step}"] = [
      'name' => __('Create config file', 'worry-proof-backup'),
      'callback_fn' => [$this, 'step_create_config_file'],
      'context' => [
        'backup_types' => $this->backup_types,
      ]
    ];

    foreach ($this->backup_types as $type) {
      $step++;
      $this->steps["step__{$step}"] = [
        'name' => sprintf(__("Backup %s", 'worry-proof-backup'), $type), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
        'callback_fn' => [$this, "step_{$type}"]
      ];
    }

    $this->steps["step__" . ($step + 1)] = [
      'name' => __('Finish', 'worry-proof-backup'),
      'callback_fn' => [$this, 'step_finish']
    ];
  }

  public function handle_cron() {
    $cron = new WORRPB_Cron_Manager('worrprba_cron_manager', function ($context) {
      $step = (int) ($context['step'] ?? 0);
      $completed = $context['completed'] ?? false;
      $last_key = $context['period_key'] ?? '';

      if ($last_key !== $this->period_key) {
        $step = 0;
        $completed = false;
        // error_log("🆕 START BACKUP {$this->type}: {$this->period_key}");
      }

      if ($completed) {
        // error_log("✅ COMPLETED BACKUP {$this->type} {$this->period_key} → SKIP");
        return $context;
      }

      $step_key = "step__{$step}";
      if (!isset($this->steps[$step_key])) {
        return $context;
      }

      $callback = $this->steps[$step_key]['callback_fn'];
      $step_context = $this->steps[$step_key]['context'] ?? [];

      $context = array_merge($context, $step_context);
      $result = call_user_func($callback, $context);

      return array_merge($context, $result ?? [], [
        'period_key' => $this->period_key,
      ]);
    });

    $cron->run_if_due(300); // 5 minutes
  }

  // 🧩 Step 1: Create config
  public function step_create_config_file($context) {
    $backup_name = 'Backup Schedule (' . gmdate('F j, Y \a\t g:i A') . ')';
    $backup_types = $context['backup_types'] ?? [];
    $step = (int) ($context['step'] ?? 0);

    $config_file = worrprba_generate_config_file([
      'backup_name' => $backup_name,
      'backup_types' => implode(',', $backup_types),
    ]);

    if (is_wp_error($config_file)) return $config_file;

    $name_folder = $config_file['name_folder'] ?? '';
    $backup_folder = $config_file['backup_folder'] ?? '';

    if (empty($name_folder)) {
      return new WP_Error('name_folder_empty', __('Name folder is empty', 'worry-proof-backup'));
    }

    return [
      'completed' => false,
      'start_time' => time(),
      'name_folder' => $name_folder,
      'backup_folder' => $backup_folder,
      'backup_ssid' => '', // reset backup_ssid
      'step' => $step + 1,
    ];
  }

  // 🧩 Step 2: Backup database
  public function step_database($context) {
    ignore_user_abort(true);
    $step = (int) ($context['step'] ?? 0);
    $ssid = $context['name_folder'] ?? '';
    $backup_folder = $context['backup_folder'] ?? '';

    if (empty($ssid)) {
      return new WP_Error('backup_ssid_empty', __('Backup SSID is empty', 'worry-proof-backup'));
    }

    $backup = new WORRPB_Database(5000, $ssid);
    if (is_wp_error($backup)) return $backup;

    if (empty($context['backup_ssid'])) {
      $result = $backup->startBackup();
      if (is_wp_error($result)) return $result;

      return ['backup_ssid' => $ssid];
    }

    try {
      while (true) {
        $progress = $backup->processStep();
        if (is_wp_error($progress)) return $progress;
        if ($progress['done']) break;
      }
    } catch (Exception $e) {
      worrprba_update_config_file($backup_folder, ['backup_status' => 'fail']);
      worrprba_log('😵 BACKUP DATABASE FAILED: ' . $e->getMessage());
      return ['completed' => true, 'end_time' => time()];
    }

    $result = $backup->finishBackup();
    if (is_wp_error($result)) return $result;

    return ['step' => $step + 1];
  }

  // 🧩 Step 3–5: plugin, theme, uploads
  public function step_plugin($context) {
    return $this->backup_folder_step(WP_PLUGIN_DIR, 'plugins.zip', $context, ['worry-proof-backup']);
  }

  public function step_theme($context) {
    return $this->backup_folder_step(WP_CONTENT_DIR . '/themes/', 'themes.zip', $context);
  }

  public function step_uploads($context) {
    return $this->backup_folder_step(WP_CONTENT_DIR . '/uploads/', 'uploads.zip', $context, [
      'worry-proof-backup', 'worry-proof-backup-zip', 'worry-proof-backup-cron-manager'
    ]);
  }

  protected function backup_folder_step($source, $zip_name, $context, $exclude = []) {
    ignore_user_abort(true);
    $step = (int) ($context['step'] ?? 0);
    $name_folder = $context['name_folder'] ?? '';
    $backup_folder = $context['backup_folder'] ?? '';

    $backup = new WORRPB_File_System([
      'source_folder' => $source,
      'destination_folder' => $name_folder,
      'zip_name' => $zip_name,
      'exclude' => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
    ]);

    if (is_wp_error($backup) || is_wp_error($zip = $backup->runBackup())) {
      $error = is_wp_error($backup) ? $backup : $zip;
      worrprba_log("😵 BACKUP FAILED ($zip_name): " . $error->get_error_message());
      worrprba_update_config_file($backup_folder, ['backup_status' => 'fail']);
      return ['completed' => true, 'end_time' => time()];
    }

    return ['step' => $step + 1];
  }

  // 🧩 Step 6: Finish
  public function step_finish($context) {
    $backup_folder = $context['backup_folder'] ?? '';
    $size = worrprba_calc_folder_size($backup_folder);
    $result = worrprba_update_config_file($backup_folder, [
      'backup_status' => 'completed',
      'backup_size' => worrprba_format_bytes($size),
    ]);

    if (is_wp_error($result)) {
      return new WP_Error('update_config_file_failed', $result->get_error_message());
    }

    // add hook after backup completed
    do_action('worry-proof-backup:after_backup_cron_completed', $this->config, $context);

    return [
      'completed' => true,
      'end_time' => time(),
      'backup_ssid' => '',
      'name_folder' => '',
      'backup_folder' => '',
    ];
  }
}

// ⏱ init handler
new WORRPB_Cron_Handler();
