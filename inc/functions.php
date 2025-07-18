<?php 
/**
 * Functions file
 */

/**
 * Load template file
 * 
 * @param string $template_name Template name
 * @param array $args Arguments to pass to template
 * @param bool $echo Whether to echo the template or return it
 * @return string|void
 */
function worrprba_load_template($template_name, $args = array(), $echo = true) {
  // Get default template path
  $default_template_path = WORRPRBA_PLUGIN_PATH . 'templates/' . $template_name . '.php';
  
  // Allow template path to be overridden via filter
  $template_path = apply_filters('worrprba_template_path', $default_template_path, $template_name);

  // Check if template exists
  if (!file_exists($template_path)) {
    return '';
  }

  // Extract args to make them available in template
  if (!empty($args)) {
    extract($args);
  }

  // Start output buffering
  ob_start();

  // Include template
  include $template_path;

  // Get template content
  $template_content = ob_get_clean();

  // Echo or return template content
  if ($echo) {
    echo wp_kses_post($template_content);
  } else {
    return wp_kses_post($template_content);
  }
}

/**
 * Register admin page
 */
function worrprba_register_admin_page() {
  // check if user is admin role
  if (!current_user_can('manage_options')) {
    return;
  }

  add_management_page(
    __('Worry Proof Backup', 'worry-proof-backup'), // Page title
    __('Backup Tools', 'worry-proof-backup'), // Menu title
    'manage_options', // Capability required
    'worry-proof-backup', // Menu slug
    'worrprba_admin_page' // Function to display the page
  );
}

function worrprba_admin_page() {

  # plugin version
  $plugin_version = WORRPRBA_PLUGIN_VERSION;

  # load template admin page
  worrprba_load_template('admin-page', array(
    'plugin_version' => $plugin_version,
  ), true);
}

function worrprba_get_backups() {

  // scan folder: wp-content/uploads/worry-proof-backup/
  $upload_dir = wp_upload_dir();
  $backup_folder = $upload_dir['basedir'] . '/worry-proof-backup/';

  // check if $backup_folder is exists
  if (!file_exists($backup_folder)) {
    return array();
  }

  // get all folders in $backup_folder
  $folders = glob($backup_folder . '/*', GLOB_ONLYDIR);
  
  // check if $folders is not empty
  if (empty($folders)) {
    return array();
  }

  $backups = array();

  // loop through $folders
  foreach ($folders as $folder) {

    // get folder name
    $folder_name = basename($folder);

    // get config file
    $config_file = $folder . '/config.json';

    // check if $config_file is exists
    if (!file_exists($config_file)) {
      continue;
    }

    // get config file content
    $config_file_content = file_get_contents($config_file);

    // check if $config_file_content is not empty
    if (empty($config_file_content)) {
      continue;
    }

    // decode config file content
    $config_file_content = json_decode($config_file_content, true);

    // check if $config_file_content is not empty
    if (empty($config_file_content)) {
      continue;
    }

    $config_file_content['type'] = explode(',', $config_file_content['backup_types']);

    $backups[] = [
      'id' => $config_file_content['backup_id'],
      'name' => $config_file_content['backup_name'],
      'status' => $config_file_content['backup_status'],
      'date' => gmdate('Y-m-d H:i:s', strtotime($config_file_content['backup_date'])),
      'size' => $config_file_content['backup_size'],
      'type' => explode(',', $config_file_content['backup_types']),
      'folder_name' => $folder_name,
      'site_url' => $config_file_content['site_url'],
    ];
  }

  // sort $backups by date
  usort($backups, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
  });

  // return $backups
  return $backups;
}

/**
 * Check if WP-CLI is available
 * 
 * @return bool
 */
function worrprba_is_wp_cli_available() {
  if ( defined( 'WP_CLI' ) && WP_CLI ) {
      return true; // WP-CLI is running
  }

  $cli_path = shell_exec( 'which wp' );
  return ! empty( $cli_path );
}

function worrprba_is_current_admin_page( $screen_id_check = '' ) {
  if ( ! is_admin() ) {
      return false;
  }

  $screen = get_current_screen();

  if ( ! $screen || ! isset( $screen->id ) ) {
      return false;
  }

  return $screen->id === $screen_id_check;
}

/**
 * Get server metrics relevant for backup configuration.
 *
 * Returns an array with:
 * - disk_free_space: Free disk space in bytes
 * - disk_total_space: Total disk space in bytes
 * - memory_limit: PHP memory limit in bytes
 * - memory_usage: Current PHP memory usage in bytes
 * - max_execution_time: PHP max execution time in seconds
 * - upload_max_filesize: Max upload file size in bytes
 * - post_max_size: Max POST size in bytes
 * - safe_mode: Whether PHP safe mode is enabled (legacy, for old PHP)
 * - server_software: Server software string
 * - php_version: PHP version string
 * - wp_version: WordPress version string
 * - ZipArchive: Whether ZipArchive is available
 * - WP Debug: Whether WP debug is enabled
 *
 * @return array
 */
function worrprba_get_server_metrics() {
  // Disk space
  $root = ABSPATH;
  $disk_free_space = @disk_free_space($root);
  $disk_total_space = @disk_total_space($root);

  // PHP memory
  $memory_limit = worrprba_return_bytes(@ini_get('memory_limit'));
  $memory_usage = function_exists('memory_get_usage') ? memory_get_usage() : null;

  // PHP execution time
  $max_execution_time = @ini_get('max_execution_time');

  // Upload limits
  $upload_max_filesize = worrprba_return_bytes(@ini_get('upload_max_filesize'));
  $post_max_size = worrprba_return_bytes(@ini_get('post_max_size'));

  // Safe mode (legacy, for old PHP)
  $safe_mode = false;
  if (function_exists('ini_get')) {
    $safe_mode = strtolower(@ini_get('safe_mode')) == 'on' || @ini_get('safe_mode') == 1;
  }

  // Server info
  $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
  $php_version = phpversion();

  // WordPress version
  $wp_version = get_bloginfo('version');

  // MySQL version
  global $wpdb;
  $mysql_version = $wpdb->db_version();

  return array(
    'disk_free_space'    => $disk_free_space,
    'disk_total_space'   => $disk_total_space,
    'memory_limit'       => $memory_limit,
    'memory_usage'       => $memory_usage,
    'max_execution_time' => $max_execution_time,
    'upload_max_filesize'=> $upload_max_filesize,
    'post_max_size'      => $post_max_size,
    'safe_mode'          => $safe_mode,
    'server_software'    => $server_software,
    'php_version'        => $php_version,
    'wp_version'         => $wp_version,
    'mysql_version'      => $mysql_version,
    'ZipArchive'         => class_exists('ZipArchive'),
    'WP_Debug'           => defined('WP_DEBUG') && WP_DEBUG,
    'WP_CLI'             => worrprba_is_wp_cli_available(),
    'WP_Max_Upload_Size' => wp_max_upload_size(),
    'plugin_version'     => WORRPRBA_PLUGIN_VERSION,
  );
}

/**
 * Convert PHP shorthand byte values (e.g. 128M, 2G) to integer bytes.
 *
 * @param string $val
 * @return int
 */
function worrprba_return_bytes($val) {
  $val = trim($val);
  $last = strtolower($val[strlen($val)-1]);
  $num = (int)$val;
  switch($last) {
    case 'g':
      $num *= 1024;
      // no break
    case 'm':
      $num *= 1024;
      // no break
    case 'k':
      $num *= 1024;
  }
  return $num;
}

/**
 * Save data to file
 * 
 * @param string $file_path
 * @param string $data
 * @return bool
 */
function worrprba_save_data_to_file($file_path, $data) {
  // Ensure the directory exists
  $directory = dirname($file_path);
  if (!file_exists($directory)) {
    global $wp_filesystem;
    
    if (empty($wp_filesystem)) {
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      WP_Filesystem();
    }
    
    if (!$wp_filesystem->mkdir($directory, 0755)) {
      return new WP_Error('mkdir_failed', esc_html__('Could not create directory for file: ', 'worry-proof-backup') . $directory);
    }
  }

  // Write data to file
  $result = file_put_contents($file_path, $data);

  if ($result === false) {
    return new WP_Error('write_failed', esc_html__('Could not write data to file: ', 'worry-proof-backup') . $file_path);
  }

  return true;
}

/**
 * Check if file exists and create directory if needed using WP_Filesystem
 * 
 * @param string $file_path Full path to the file
 * @param int $permissions Directory permissions (default: 0755)
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function worrprba_ensure_file_directory($file_path, $permissions = 0755) {
  global $wp_filesystem;
  
  // Initialize WP_Filesystem if not already done
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }
  
  // Get directory path
  $directory = dirname($file_path);
  
  // Check if directory exists
  if (!$wp_filesystem->exists($directory)) {
    // Create directory recursively
    if (!$wp_filesystem->mkdir($directory, $permissions)) {
      return new WP_Error(
        'mkdir_failed', 
        esc_html__('Could not create directory: ', 'worry-proof-backup') . $directory
      );
    }
  }
  
  return true;
}

/**
 * Create directory using WP_Filesystem (replacement for mkdir)
 * 
 * @param string $path Directory path to create
 * @param int $permissions Directory permissions (default: 0755)
 * @param bool $recursive Whether to create parent directories (default: true)
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function worrprba_mkdir($path, $permissions = 0755, $recursive = true) {
  global $wp_filesystem;
  
  // Initialize WP_Filesystem if not already done
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }
  
  // Check if directory already exists
  if ($wp_filesystem->exists($path)) {
    return true;
  }
  
  // Create directory
  if (!$wp_filesystem->mkdir($path, $permissions)) {
    return new WP_Error(
      'mkdir_failed', 
      esc_html__('Could not create directory: ', 'worry-proof-backup') . $path
    );
  }
  
  return true;
}


/**
 * generate config file 
 * Create a new folder in uploads/worry-proof-backup/backup_<random_string>
 * Create a new file in the folder: config.json
 * The file should contain the following information:
 * - backup_id
 * - backup_name
 * - backup_types
 * - backup_date
 * - backup_description
 * - backup_author_email
 * - backup_status
 * - backup_size
 */
function worrprba_generate_config_file($args = array()) {
  global $wpdb;
  
  $defaults = array(
    'backup_id' => wp_generate_uuid4(),
    'backup_name' => '',
    'backup_types' => '',
    'backup_date' => gmdate('c'),
    'backup_description' => '',
    'backup_author_email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
    'backup_status' => 'pending',
    'backup_size' => '???',
    'site_url' => home_url(),
    'table_prefix' => $wpdb->prefix,
  );
  $args = wp_parse_args($args, $defaults);

  // Sanitize
  $args['backup_name'] = sanitize_text_field($args['backup_name']);
  $args['backup_description'] = sanitize_textarea_field($args['backup_description']);
  $args['site_url'] = esc_url_raw($args['site_url']);
  $args['backup_author_email'] = sanitize_email($args['backup_author_email']);

  $upload_dir = wp_upload_dir();
  $name_folder = 'backup_' . $args['backup_id'] . '_' . gmdate('Y-m-d_H-i-s');
  $root_backup_folder = $upload_dir['basedir'] . '/worry-proof-backup/';
  $backup_folder = $root_backup_folder . $name_folder;

  // check if $root_backup_folder is exists
  if (!file_exists($root_backup_folder)) {
    $check_root_folder = worrprba_mkdir($root_backup_folder);

    if(is_wp_error($check_root_folder)) {
      return new WP_Error('mkdir_failed', $check_root_folder->get_error_message());
    }
  }

  // check if $backup_folder is exists via worrprba_ensure_file_directory
  $check_folder = worrprba_mkdir($backup_folder);

  if(is_wp_error($check_folder)) {
    return new WP_Error('mkdir_failed', $check_folder->get_error_message());
  }
  
  // if (!file_exists($backup_folder)) {
  //   if (!mkdir($backup_folder, 0755, true)) {
  //     return new WP_Error('mkdir_failed', 'Oops! 🤦‍♂️ Could not create backup folder. Please check folder permissions or try again. If the problem persists, contact your administrator. We\'re rooting for you! 💪✨');
  //   }
  // }

  $config_file = $backup_folder . '/config.json';
  $result = file_put_contents($config_file, json_encode($args, JSON_PRETTY_PRINT));

  if ($result === false) {
    return new WP_Error('write_failed', 'Oops! 🤦‍♂️ Could not write config file. Please check folder permissions or try again. If the problem persists, contact your administrator. We\'re rooting for you! 💪✨');
  }

  return [
    'backup_folder' => $backup_folder,
    'config_file' => $config_file,
    'name_folder' => $name_folder,
  ];
}

/**
 * Update any field(s) in the config file.
 * 
 * @param string $backup_folder
 * @param array $fields Associative array of fields to update, e.g. ['backup_status' => 'completed']
 * @return bool|WP_Error
 */
function worrprba_update_config_file($backup_folder, $fields) {
  $config_file = $backup_folder . '/config.json';

  // Check if config file exists
  if (!file_exists($config_file)) {
    return new WP_Error('config_file_not_found', 'Config file not found');
  }

  // Get config file content
  $config_file_content = file_get_contents($config_file);

  // Check if config file content is not empty
  if (empty($config_file_content)) {
    return new WP_Error('config_file_empty', 'Config file is empty');
  }

  // Decode config file content
  $config_data = json_decode($config_file_content, true);

  if (!is_array($config_data)) {
    return new WP_Error('config_file_invalid', 'Config file is not valid JSON');
  }

  // Update fields
  foreach ($fields as $key => $value) {
    $config_data[$key] = $value;
  }

  // Save config file
  $result = file_put_contents($config_file, json_encode($config_data, JSON_PRETTY_PRINT));

  // Check if $result is false
  if ($result === false) {
    return new WP_Error('write_failed', 'Could not write config file: ' . $config_file);
  }

  return true;
}

/**
 * Calculate the size of a folder (optimized, supports >2GB)
 *
 * @param string $folder Absolute path to folder
 * @return float Folder size (bytes)
 */
function worrprba_calc_folder_size($folder) {
  $size = 0.0;

  if (!is_dir($folder)) {
      return 0;
  }

  try {
      $iterator = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
          RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($iterator as $file) {
          // Skip symlinks (to avoid loop)
          if ($file->isLink()) {
              continue;
          }

          if ($file->isFile()) {
              $file_size = $file->getSize();
              if ($file_size !== false) {
                  $size += (float) $file_size;
              }
          }
      }
  } catch (Exception $e) {
      // Optionally log error: $e->getMessage()
      return 0;
  }

  return $size;
}

/**
 * Format bytes to MB, GB...
 *
 * @param int $bytes
 * @param int $precision
 * @return string
 */
function worrprba_format_bytes($bytes, $precision = 2) {
  $units = array('B', 'KB', 'MB', 'GB', 'TB');

  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);

  $bytes /= pow(1024, $pow);

  return round($bytes, $precision) . ' ' . $units[$pow];
}


function worrprba_rmdir( $dir_path ) {
  if ( ! function_exists( 'WP_Filesystem' ) ) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
  }

  global $wp_filesystem;

  if ( ! WP_Filesystem() ) {
      return new WP_Error( 'filesystem_init_failed', 'Unable to initialize WP_Filesystem.' );
  }

  // Normalize path
  $dir_path = trailingslashit( $dir_path );

  if ( ! $wp_filesystem->is_dir( $dir_path ) ) {
      return new WP_Error( 'not_a_directory', 'Path is not a directory.' );
  }

  if ( ! $wp_filesystem->delete( $dir_path, true, 'd' ) ) {
      return new WP_Error( 'delete_failed', 'Failed to delete the directory.' );
  }

  return true;
}

/**
 * Remove a folder and its contents (WordPress safe, with WP_Error)
 *
 * @param string $folder Absolute path to folder
 * @return true|WP_Error True if removed, WP_Error on failure
 */
function worrprba_remove_folder($folder) {
  if (!is_dir($folder)) {
    // Nothing to do
    return true;
  }

  // get last path of $folder
  $name_folder = basename($folder);

  try {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
      /** @var SplFileInfo $file */
      $path = $file->getPathname();

      if ($file->isDir()) {
        if (!worrprba_rmdir($path)) {
          return new WP_Error('remove_folder_failed', esc_html__('Failed to remove directory: ', 'worry-proof-backup') . $path);
        }
      } else {
        if (!wp_delete_file($path)) {
          return new WP_Error('remove_file_failed', esc_html__('Failed to remove file: ', 'worry-proof-backup') . $path);
        }
      }
    }

    // check and delete zip file in uploads > worry-proof-backup-zip > $folder .zip
    $upload_dir = wp_upload_dir();
    $backup_zip_path = $upload_dir['basedir'] . '/worry-proof-backup-zip/' . $name_folder . '.zip';
    if (file_exists($backup_zip_path)) {
      wp_delete_file($backup_zip_path);
    }

    // Finally remove the folder itself
    if (!worrprba_rmdir($folder)) {
      return new WP_Error('remove_folder_failed', esc_html__('Failed to remove directory: ', 'worry-proof-backup') . $folder);
    }

    return true;
  } catch (Exception $e) {
    return new WP_Error('exception', $e->getMessage());
  }
}

function worrprba_get_config_file($folder_name) {
  $upload_dir = wp_upload_dir();
  $backup_folder = $upload_dir['basedir'] . '/worry-proof-backup/' . $folder_name . '/config.json';

  // check if $backup_folder is exists
  if (!file_exists($backup_folder)) {
    return new WP_Error('config_file_not_found', esc_html__('Config file not found', 'worry-proof-backup'));
  }

  // get config file content
  global $wp_filesystem;
    
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }

  $config_file_content = $wp_filesystem->get_contents($backup_folder);

  // check if $config_file_content is not empty
  if (empty($config_file_content)) {
    return new WP_Error('config_file_empty', esc_html__('Config file is empty', 'worry-proof-backup'));
  }

  // decode config file content
  $config_data = json_decode($config_file_content, true);

  // check if $config_data is not empty
  if (empty($config_data)) {
    return new WP_Error('config_data_empty', esc_html__('Config data is empty', 'worry-proof-backup'));
  }

  return $config_data;
}

// send report email
function worrprba_send_report_email($args = array()) {
  $defaults = array(
    'name' => '',
    'email' => '',
    'type' => '',
    'description' => '',
  );

  // validate $args
  if (empty($args['name']) || empty($args['email']) || empty($args['type']) || empty($args['description'])) {
    return new WP_Error('invalid_args', esc_html__('Invalid arguments', 'worry-proof-backup'));
  }

  $args = wp_parse_args($args, $defaults);

  // get system info
  $system_info = worrprba_get_server_metrics();
  
  // wordpress domain
  $wordpress_domain = get_bloginfo('url');

  $to = 'mike.beplus@gmail.com';
  $subject = esc_html__('WP Backup Report', 'worry-proof-backup');
  $body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: "Space Mono", monospace; 
            line-height: 1.5; 
            color: #2c3e50; 
            max-width: 700px; 
            margin: 0 auto; 
            padding: 30px; 
            background-color: #fafafa;
        }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 30px; 
            border-radius: 12px; 
            margin-bottom: 30px; 
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header h2 { 
            margin: 0 0 10px 0; 
            font-size: 24px; 
            font-weight: 700;
        }
        .header p { 
            margin: 0; 
            opacity: 0.9; 
            font-size: 14px;
        }
        .section { 
            background: white; 
            padding: 25px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 4px solid #667eea;
        }
        .label { 
            font-weight: 700; 
            color: #34495e; 
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .value { 
            margin-left: 15px; 
            color: #2c3e50;
            font-size: 14px;
        }
        .system-info { 
            background: #f8f9fa; 
            padding: 20px; 
            border-radius: 6px; 
            font-family: "Space Mono", monospace; 
            font-size: 12px;
            border: 1px solid #e9ecef;
        }
        .system-info table {
            width: 100%; 
            border-collapse: collapse; 
            font-family: "Space Mono", monospace; 
            font-size: 11px;
        }
        .system-info td {
            padding: 8px 12px; 
            border-bottom: 1px solid #e9ecef; 
            vertical-align: top;
        }
        .system-info td:first-child {
            font-weight: 700; 
            width: 35%; 
            color: #495057;
        }
        .system-info td:last-child {
            color: #6c757d;
        }
        .footer { 
            margin-top: 40px; 
            padding-top: 25px; 
            border-top: 2px solid #e9ecef; 
            font-size: 11px; 
            color: #6c757d; 
            text-align: center;
            font-family: "Space Mono", monospace;
        }
        .description {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 3px solid #28a745;
            margin-top: 10px;
            white-space: pre-wrap;
            font-size: 13px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>WP Backup Issue Report</h2>
        <p>New issue report submitted via WP Backup plugin</p>
    </div>
    
    <div class="section">
        <div><span class="label">Name:</span><span class="value">' . esc_html($args['name']) . '</span></div>
        <div><span class="label">Email:</span><span class="value">' . esc_html($args['email']) . '</span></div>
        <div><span class="label">Type:</span><span class="value">' . esc_html($args['type']) . '</span></div>
    </div>
    
    <div class="section">
        <div class="label">Description:</div>
        <div class="description">' . esc_html($args['description']) . '</div>
    </div>
    
    <div class="section">
        <div class="label">WordPress Domain:</div>
        <div class="value">' . esc_html($wordpress_domain) . '</div>
    </div>
    
    <div class="section">
        <div class="label">System Information:</div>
        <div class="system-info">
            <table>
                <tr>
                    <td>Disk Free Space:</td>
                    <td>' . esc_html(worrprba_format_bytes($system_info['disk_free_space'])) . '</td>
                </tr>
                <tr>
                    <td>Disk Total Space:</td>
                    <td>' . esc_html(worrprba_format_bytes($system_info['disk_total_space'])) . '</td>
                </tr>
                <tr>
                    <td>Memory Limit:</td>
                    <td>' . esc_html(worrprba_format_bytes($system_info['memory_limit'])) . '</td>
                </tr>
                <tr>
                    <td>Memory Usage:</td>
                    <td>' . esc_html(worrprba_format_bytes($system_info['memory_usage'])) . '</td>
                </tr>
                <tr>
                    <td>Max Execution Time:</td>
                    <td>' . esc_html($system_info['max_execution_time']) . ' seconds</td>
                </tr>
                <tr>
                    <td>Upload Max Filesize:</td>
                    <td>' . esc_html(worrprba_format_bytes($system_info['upload_max_filesize'])) . '</td>
                </tr>
                <tr>
                    <td>Post Max Size:</td>
                    <td>' . esc_html(worrprba_format_bytes($system_info['post_max_size'])) . '</td>
                </tr>
                <tr>
                    <td>Safe Mode:</td>
                    <td>' . esc_html($system_info['safe_mode'] ? 'Enabled' : 'Disabled') . '</td>
                </tr>
                <tr>
                    <td>Server Software:</td>
                    <td>' . esc_html($system_info['server_software']) . '</td>
                </tr>
                <tr>
                    <td>PHP Version:</td>
                    <td>' . esc_html($system_info['php_version']) . '</td>
                </tr>
                <tr>
                    <td>WordPress Version:</td>
                    <td>' . esc_html($system_info['wp_version']) . '</td>
                </tr>
                <tr>
                    <td>MySQL Version:</td>
                    <td>' . esc_html($system_info['mysql_version']) . '</td>
                </tr>
                <tr>
                    <td>ZipArchive:</td>
                    <td>' . esc_html($system_info['ZipArchive'] ? 'Available' : 'Not Available') . '</td>
                </tr>
                <tr>
                    <td>WP Debug:</td>
                    <td>' . esc_html($system_info['WP_Debug'] ? 'Enabled' : 'Disabled') . '</td>
                </tr>
                <tr>
                    <td>WP CLI:</td>
                    <td>' . esc_html($system_info['WP_CLI'] ? 'Available' : 'Not Available') . '</td>
                </tr>
                <tr>
                    <td>WP Backup Version:</td>
                    <td>' . esc_html($system_info['plugin_version']) . '</td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="footer">
        <p>This report was automatically generated by the WP Backup plugin.</p>
        <p>Report submitted on: ' . gmdate('Y-m-d H:i:s') . '</p>
    </div>
</body>
</html>';

  // header html
  $headers = array('Content-Type: text/html; charset=UTF-8');

  // send email
  $result = wp_mail($to, $subject, $body, $headers);

  // check if $result is false
  if ($result === false) {
    return new WP_Error('send_email_failed', 'Failed to send email');
  }

  return true;
}

// check backup download available
function worrprba_get_backup_download_zip_path($backup_folder_name = '') {
  // find backup download zip in uploads > worry-proof-backup-zip > $backup_folder_name .zip
  $upload_dir = wp_upload_dir();
  $backup_zip_path = $upload_dir['basedir'] . '/worry-proof-backup-zip/' . $backup_folder_name . '.zip';

  // check if file exists
  if (file_exists($backup_zip_path)) {
    // return uri of file
    return $upload_dir['baseurl'] . '/worry-proof-backup-zip/' . $backup_folder_name . '.zip';
  }

  return false;
}

// worrprba_create_backup_zip
function worrprba_create_backup_zip($backup_folder_name = '') {
  // create backup zip in uploads > worry-proof-backup-zip > $backup_folder_name .zip
  $upload_dir = wp_upload_dir();
  $backup_zip_path = $upload_dir['basedir'] . '/worry-proof-backup-zip/' . $backup_folder_name . '.zip';

  // check if file exists
  if (file_exists($backup_zip_path)) {

    // return uri of file 
    return $upload_dir['baseurl'] . '/worry-proof-backup-zip/' . $backup_folder_name . '.zip';
  }

  $backup_folder_path = $upload_dir['basedir'] . '/worry-proof-backup/' . $backup_folder_name;

  // create folder "worry-proof-backup-zip" if not exists
  if (!file_exists($upload_dir['basedir'] . '/worry-proof-backup-zip/')) {
    $result = wp_mkdir_p($upload_dir['basedir'] . '/worry-proof-backup-zip/');
    if (is_wp_error($result)) {
      return new WP_Error('create_backup_zip_failed', $result->get_error_message());
    }
  }

  // create zip file backup
  $backup_zip_path = $upload_dir['basedir'] . '/worry-proof-backup-zip/' . $backup_folder_name . '.zip';

  // create zip file
  $zip = new ZipArchive();
  $zip->open($backup_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

  $folderPath = realpath($backup_folder_path);

  if (!is_dir($folderPath)) {
    return new WP_Error('create_backup_zip_failed', 'Backup folder not found');
  }

  $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($folderPath),
      RecursiveIteratorIterator::LEAVES_ONLY
  );

  $exclude_files = ['restore.log', '__process_restore.log'];

  foreach ($files as $name => $file) {
    if (!$file->isDir()) {
      $filePath = $file->getRealPath();
      $relativePath = substr($filePath, strlen($folderPath) + 1);

      // check if file is in $exclude_files
      if (in_array($relativePath, $exclude_files)) {
        continue;
      }

      $zip->addFile($filePath, $relativePath);
    }
  }

  $zip->close();

  // check if file exists
  if (!file_exists($backup_zip_path)) {
    return new WP_Error('create_backup_zip_failed', 'Backup zip file failed, please try again!');
  }

  return $upload_dir['baseurl'] . '/worry-proof-backup-zip/' . $backup_folder_name . '.zip';
}

// func create process restore id, includes params backup filename
function worrprba_create_process_restore_id($backup_folder = '') {
  // check $backup_folder is not empty
  if (empty($backup_folder)) {
    return new WP_Error('backup_folder_empty', esc_html__('backup folder is empty', 'worry-proof-backup'));
  }

  $__id = 'backup_restore.' . uniqid() . '.process';

  // create process restore id in uploads > worry-proof-backup-process-restore > $backup_folder .json
  $upload_dir = wp_upload_dir();
  $backup_restore_path = $upload_dir['basedir'] . '/worry-proof-backup/' . $backup_folder;

  global $wp_filesystem;
    
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }
  
  if (!$wp_filesystem->exists($backup_restore_path)) {
    return new WP_Error('backup_folder_not_found', esc_html__('backup folder not found: ', 'worry-proof-backup') . $backup_restore_path);
  }

  // create a new file to $backup_restore_path + __process_restore.log
  $process_restore_log_path = $backup_restore_path . '/__process_restore.log';

  // create file
  $result = $wp_filesystem->put_contents($process_restore_log_path, $__id);
  if (is_wp_error($result)) {
    return new WP_Error('create_process_restore_id_failed', $result->get_error_message());
  }

  return $__id;
}

// validate process restore id, params process_restore_id, backup_folder
function worrprba_validate_process_restore_id($process_restore_id = '', $backup_folder = '') {
  // check $process_restore_id is not empty
  if (empty($process_restore_id)) {
    return new WP_Error('process_restore_id_empty', esc_html__('process restore id is empty', 'worry-proof-backup'));
  }

  // check $backup_folder is not empty
  if (empty($backup_folder)) {
    return new WP_Error('backup_folder_empty', esc_html__('backup folder is empty', 'worry-proof-backup'));
  }

  $upload_dir = wp_upload_dir();
  $backup_restore_path = $upload_dir['basedir'] . '/worry-proof-backup/' . $backup_folder;
  $process_restore_log_path = $backup_restore_path . '/__process_restore.log';

  global $wp_filesystem;
    
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }

  // check if file exists
  if (!$wp_filesystem->exists($process_restore_log_path)) {
    return new WP_Error('process_restore_log_not_found', esc_html__('process restore log not found', 'worry-proof-backup'));
  }

  // get process restore log content
  $process_restore_log_content = $wp_filesystem->get_contents($process_restore_log_path);

  // check if $process_restore_log_content is not empty
  if (empty($process_restore_log_content)) {
    return new WP_Error('process_restore_log_empty', esc_html__('process restore log is empty', 'worry-proof-backup'));
  }

  // check if $process_restore_log_content is equal to $process_restore_id
  if ($process_restore_log_content !== $process_restore_id) {
    return new WP_Error('process_restore_id_invalid', esc_html__('process restore id is invalid', 'worry-proof-backup'));
  }

  return true;
}

// delete process restore id, params backup_folder
function worrprba_delete_process_restore_id($backup_folder = '') {
  // check $backup_folder is not empty
  if (empty($backup_folder)) {
    return new WP_Error('backup_folder_empty', esc_html__('backup folder is empty', 'worry-proof-backup'));
  }

  $upload_dir = wp_upload_dir();
  $backup_restore_path = $upload_dir['basedir'] . '/worry-proof-backup/' . $backup_folder;
  $process_restore_log_path = $backup_restore_path . '/__process_restore.log';

  global $wp_filesystem;
    
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }

  // check if file exists
  if (!$wp_filesystem->exists($process_restore_log_path)) {
    return new WP_Error('process_restore_log_not_found', esc_html__('process restore log not found', 'worry-proof-backup'));
  }

  // delete file
  $result = $wp_filesystem->delete($process_restore_log_path);
  if (is_wp_error($result)) {
    return new WP_Error('delete_process_restore_log_failed', $result->get_error_message());
  }

  return true;
}

function worrprba_get_period_key($type = 'weekly') {
  switch ($type) {
      case 'daily': return gmdate('Y-m-d');
      case 'monthly': return gmdate('Y-m');
      case 'weekly': return gmdate('o-W');
      case 'yearly': return gmdate('Y');
      case 'hourly': return gmdate('Y-m-d-H');
      default: return 'custom-' . gmdate('U');
  }
}

function worrprba_save_backup_schedule_config($config = array()) {

  // check $config is not empty
  if (empty($config)) {
    return new WP_Error('config_empty', esc_html__('config is empty', 'worry-proof-backup'));
  }

  // save config to worry-proof-backup-cron-manager/worry-proof-backup-schedule-config.json
  $upload_dir = wp_upload_dir();
  $backup_cron_manager_path = $upload_dir['basedir'] . '/worry-proof-backup-cron-manager/';

  // create folder if not exists
  global $wp_filesystem;
    
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }

  if (!$wp_filesystem->exists($backup_cron_manager_path)) {
    $result = $wp_filesystem->mkdir($backup_cron_manager_path, 0755);
    if (!$result) {
      return new WP_Error('create_backup_cron_manager_folder_failed', esc_html__('Failed to create backup cron manager folder', 'worry-proof-backup'));
    }
  }

  // save config to worry-proof-backup-cron-manager/worry-proof-backup-schedule-config.json
  $config_path = $backup_cron_manager_path . 'worry-proof-backup-schedule-config.json';



  // save config to file
  $result = $wp_filesystem->put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT));
  if (is_wp_error($result)) {
    return new WP_Error('save_backup_schedule_config_failed', $result->get_error_message());
  }

  // add hook after save config
  do_action('worry-proof-backup:after_save_backup_schedule_config', $config);

  return true;
}

function worrprba_get_backup_schedule_config() {
  // get config from worry-proof-backup-cron-manager/worry-proof-backup-schedule-config.json
  $upload_dir = wp_upload_dir();
  $backup_cron_manager_path = $upload_dir['basedir'] . '/worry-proof-backup-cron-manager/';

  global $wp_filesystem;
    
  if (empty($wp_filesystem)) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();
  }

  // get config from file
  $config_path = $backup_cron_manager_path . 'worry-proof-backup-schedule-config.json';

  // check if file exists
  if (!$wp_filesystem->exists($config_path)) {
    return false;
  }

  // get config from file
  $config = $wp_filesystem->get_contents($config_path);
  if (is_wp_error($config)) {
    return new WP_Error('get_backup_schedule_config_failed', $config->get_error_message());
  }

  return json_decode($config, true);
}

/**
 * Delete older "Backup Schedule ..." backups, keeping only the latest N backups.
 *
 * @param int $keep_last_n_backup Number of most recent backups to keep.
 * @return bool True if completed, false if skipped.
 */
function worrprba_delete_older_backups($keep_last_n_backup = 2) {
  // get all backups
  $all_backups = worrprba_get_backups();

  // filter backups with name start with "Backup Schedule ..."
  $scheduled_backups = array_filter($all_backups, function($backup) {
    return isset($backup['name']) && strpos($backup['name'], 'Backup Schedule') === 0;
  });

  // if count of backups <= keep last n backup, skip
  if (count($scheduled_backups) <= $keep_last_n_backup) {
    return false;
  }

  // sort backups by created_at
  usort($scheduled_backups, function($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
  });

  // get backups to delete
  $backups_to_delete = array_slice($scheduled_backups, $keep_last_n_backup);

  // delete backups
  foreach ($backups_to_delete as $backup) {
    if (empty($backup['folder_name'])) {
      continue;
    }

    $backup_folder = WP_CONTENT_DIR . '/uploads/worry-proof-backup/' . $backup['folder_name'];
    $result = worrprba_remove_folder($backup_folder);

    if (is_wp_error($result)) {
      worrprba_log(sprintf(
        "🙁 DELETE BACKUP FOLDER FAILED: %s - %s",
        esc_html($backup_folder),
        $result->get_error_message()
      ));
    }
  }

  return true;
}

// send mail to admin when backup cron completed
function worrprba_send_mail_to_admin_when_backup_cron_completed($config, $context) {
  // get admin email
  $admin_email = get_option('admin_email');

  // translators: %s: Site title.
  $subject = sprintf(esc_html__('Create Backup Successfully - %s', 'worry-proof-backup'), get_bloginfo('name')); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment

  // body is a message to admin 
  $site_name = get_bloginfo('name');
  $current_time = current_time('Y-m-d H:i:s');
  $backup_types = isset($config['types']) ? implode(', ', $config['types']) : 'All types';

  $body = '<!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Backup Completed</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    </head>
    <body style="margin: 0; padding: 20px; background-color: #f8f9fa; font-family: \'Space Mono\', monospace; color: #333333;">
      <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
        
        <!-- Header -->
        <div style="background-color: #f8f9fa; padding: 40px 30px; text-align: center; border-bottom: 1px solid #e9ecef;">
          <div style="background-color: #28a745; border-radius: 50%; width: 60px; height: 60px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
            <span style="font-size: 24px; color: #ffffff;">✓</span>
          </div>
          <h1 style="color: #28a745; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;">Backup Complete</h1>
          <p style="color: #6c757d; margin: 10px 0 0; font-size: 14px; font-weight: 400;">Data successfully backed up</p>
        </div>
        
        <!-- Content -->
        <div style="padding: 40px 30px;">
          <p style="font-size: 16px; line-height: 1.6; color: #333333; margin: 0 0 30px; font-weight: 400;">
            Scheduled backup for <strong style="color: #28a745;">' . esc_html($site_name) . '</strong> completed successfully.
          </p>
          
          <!-- Backup Details -->
          <div style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 25px; margin: 30px 0;">
            <h3 style="color: #28a745; margin: 0 0 20px; font-size: 18px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">
              Backup Details
            </h3>
            
            <div style="display: grid; gap: 12px;">
              <div style="display: flex; align-items: center; padding: 12px 16px; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 4px;">
                <span style="font-weight: 700; color: #28a745; min-width: 80px; font-size: 14px;">SITE:</span>
                <span style="color: #333333; margin-left: 10px; font-size: 14px;">' . esc_html($site_name) . '</span>
              </div>
              
              <div style="display: flex; align-items: center; padding: 12px 16px; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 4px;">
                <span style="font-weight: 700; color: #28a745; min-width: 80px; font-size: 14px;">DATE:</span>
                <span style="color: #333333; margin-left: 10px; font-size: 14px;">' . esc_html($current_time) . '</span>
              </div>
              
              <div style="display: flex; align-items: center; padding: 12px 16px; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 4px;">
                <span style="font-weight: 700; color: #28a745; min-width: 80px; font-size: 14px;">STATUS:</span>
                <span style="color: #28a745; margin-left: 10px; font-size: 14px; font-weight: 700;">COMPLETED</span>
              </div>
              
              <div style="display: flex; align-items: center; padding: 12px 16px; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 4px;">
                <span style="font-weight: 700; color: #28a745; min-width: 80px; font-size: 14px;">TYPES:</span>
                <span style="color: #333333; margin-left: 10px; font-size: 14px;">' . esc_html($backup_types) . '</span>
              </div>
            </div>
          </div>
          
          <!-- Info Box -->
          <div style="background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 6px; padding: 20px; margin: 30px 0;">
            <div style="display: flex; align-items: flex-start;">
              <span style="font-size: 16px; margin-right: 12px; color: #28a745;">></span>
              <div>
                <p style="margin: 0; color: #6c757d; font-size: 14px; line-height: 1.5; font-weight: 400;">
                  Automated backup created by scheduled system. Manage backups via WordPress admin panel.
                </p>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Footer -->
        <div style="background-color: #f8f9fa; padding: 25px 30px; text-align: center; border-top: 1px solid #e9ecef;">
          <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 10px;">
            <span style="font-size: 14px; margin-right: 8px; color: #28a745;">#</span>
            <span style="font-weight: 700; color: #28a745; text-transform: uppercase; letter-spacing: 1px;">Worry Proof Backup</span>
          </div>
          <p style="margin: 0; color: #6c757d; font-size: 12px; font-weight: 400;">
            Professional WordPress backup solution with enterprise-grade security, automated scheduling, and comprehensive data protection
          </p>
        </div>
        
      </div>
      
      <!-- Mobile Responsive Styles -->
      <style>
        @media only screen and (max-width: 600px) {
          body[style*="padding: 20px"] {
            padding: 10px !important;
          }
          
          div[style*="max-width: 600px"] {
            margin: 0 !important;
            border-radius: 4px !important;
          }
          
          div[style*="padding: 40px 30px"] {
            padding: 25px 20px !important;
          }
          
          h1[style*="font-size: 24px"] {
            font-size: 20px !important;
          }
          
          div[style*="display: grid"] {
            display: block !important;
          }
          
          div[style*="display: flex"] {
            flex-direction: column !important;
            align-items: flex-start !important;
          }
          
          span[style*="min-width: 80px"] {
            min-width: auto !important;
            margin-bottom: 5px !important;
          }
        }
        
        @media only screen and (max-width: 480px) {
          div[style*="padding: 40px 30px"] {
            padding: 20px 15px !important;
          }
          
          h1[style*="font-size: 24px"] {
            font-size: 18px !important;
          }
          
          p[style*="font-size: 16px"] {
            font-size: 14px !important;
          }
        }
      </style>
    </body>
    </html>';

  // headers html
  $headers = array('Content-Type: text/html; charset=UTF-8');

  // send mail to admin
  $result = wp_mail($admin_email, $subject, $body, $headers);

  //  check error if send mail failed
  if(is_wp_error($result)) {
    worrprba_log(sprintf(
      "🙁 SEND MAIL TO ADMIN FAILED: %s - %s",
      esc_html($admin_email),
      $result->get_error_message()
    ));
  }

  return $result;
}

/**
 * Log message 
 * 
 * @param mixed $message
 * @return void
 */
function worrprba_log( $message ) {
	if ( defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {
		if ( is_array($message) || is_object($message) ) {
			$message = print_r($message, true); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
		error_log('[WP Backup] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Safely sanitize payload array
 */
function worrprba_sanitize_payload_array( $payload ) {
  if ( ! is_array( $payload ) ) {
      return array();
  }
  
  $sanitized = array();
  
  foreach ( $payload as $key => $value ) {
      $clean_key = sanitize_key( $key );
      
      if ( is_array( $value ) ) {
          // Recursive sanitization for nested arrays
          $sanitized[ $clean_key ] = sanitize_payload_array( $value );
      } elseif ( is_string( $value ) ) { 
          // Sanitize based on expected content type
          $sanitized[ $clean_key ] = sanitize_text_field( $value );
      } elseif ( is_numeric( $value ) ) {
          $sanitized[ $clean_key ] = is_float( $value ) ? floatval( $value ) : intval( $value );
      } elseif ( is_bool( $value ) ) {
          $sanitized[ $clean_key ] = (bool) $value;
      } else {
          // Skip unknown data types
          continue;
      }
  }
  
  return $sanitized;
}