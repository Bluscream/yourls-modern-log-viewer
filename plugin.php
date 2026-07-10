<?php
/*
Plugin Name: Modern Clicks Log Viewer
Description: Displays a responsive, premium table of detailed click logs with GeoLite2 country/city detection and WhichBrowser agent parsing. Auto-downloads GeoLite2 database without keys.
Version: 1.0
Author: Antigravity
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

require_once __DIR__ . '/vendor/autoload.php';

use WhichBrowser\Parser;
use MaxMind\Db\Reader;

// Register admin menu page
yourls_add_action( 'plugins_loaded', 'mlv_admin_init' );
function mlv_admin_init() {
    yourls_register_plugin_page( 'modern_log_viewer', 'Modern Clicks Log', 'mlv_display_log_page' );
}

// Hook into plugin activation to trigger database download
yourls_add_action( 'activated_modern-log-viewer/plugin.php', 'mlv_download_db' );

function mlv_download_db() {
    $db_path = __DIR__ . '/GeoLite2-City.mmdb';
    $temp_path = $db_path . '.tmp';
    // Public mirror link that doesn't require MaxMind license key
    $url = 'https://github.com/merkez/maxmind-databases/releases/latest/download/GeoLite2-City.mmdb';
    
    $fp = fopen( $temp_path, 'w+' );
    if ( !$fp ) {
        error_log( "Modern Log Viewer: Cannot open file for writing: " . $temp_path . ". Check directory permissions of " . __DIR__ );
        return false;
    }
    
    $ch = curl_init( $url );
    curl_setopt( $ch, CURLOPT_FILE, $fp );
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
    curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
    
    $success = curl_exec( $ch );
    $curl_error = curl_error( $ch );
    curl_close( $ch );
    fclose( $fp );
    
    if ( $success && file_exists( $temp_path ) && filesize( $temp_path ) > 1000000 ) {
        rename( $temp_path, $db_path );
        return true;
    }
    
    error_log( "Modern Log Viewer: Download failed. cURL Success: " . ($success ? 'Yes' : 'No') . ", cURL Error: " . $curl_error . ", Temp File Size: " . (file_exists($temp_path) ? filesize($temp_path) : 'none') );
    
    if ( file_exists( $temp_path ) ) {
        unlink( $temp_path );
    }
    return false;
}

// Display the log page
function mlv_display_log_page() {
    $db_path = __DIR__ . '/GeoLite2-City.mmdb';
    
    // Check if user manually triggered a DB update
    if ( isset( $_POST['mlv_update_db'] ) ) {
        yourls_verify_nonce( 'mlv_db_nonce' );
        if ( mlv_download_db() ) {
            echo '<div class="updated"><p>GeoLite2 City database updated successfully!</p></div>';
        } else {
            echo '<div class="error"><p>Failed to download GeoLite2 database. Please try again later.</p></div>';
        }
    }

    // Auto download if not exists
    if ( !file_exists( $db_path ) ) {
        echo '<div class="updated"><p>Downloading GeoLite2 City database in background, please refresh in a moment...</p></div>';
        mlv_download_db();
    }

    $reader = null;
    if ( file_exists( $db_path ) ) {
        try {
            $reader = new Reader( $db_path );
        } catch ( Exception $e ) {
            error_log( "Modern Log Viewer MMDB error: " . $e->getMessage() );
        }
    }

    $db_status_html = file_exists( $db_path ) 
        ? '<span style="color:green;font-weight:bold;">Available (' . round(filesize($db_path) / 1024 / 1024, 2) . ' MB)</span>'
        : '<span style="color:red;font-weight:bold;">Not Available</span>';

    // Pagination & Search parameters
    $page = isset( $_GET['pg'] ) ? max( 1, (int)$_GET['pg'] ) : 1;
    $per_page = 50;
    $search = isset( $_GET['s'] ) ? trim( $_GET['s'] ) : '';

    $db = yourls_get_db();
    $table_log = YOURLS_DB_TABLE_LOG;

    // Build SQL query
    $where = "WHERE 1=1";
    $binds = [];
    if ( !empty($search) ) {
        $where .= " AND (shorturl LIKE :search OR ip_address LIKE :search OR referrer LIKE :search OR user_agent LIKE :search)";
        $binds['search'] = "%$search%";
    }

    // Get total count
    $total_clicks = $db->fetchValue( "SELECT COUNT(*) FROM `$table_log` $where", $binds );
    $total_pages = ceil( $total_clicks / $per_page );
    $offset = ( $page - 1 ) * $per_page;

    // Fetch click logs
    $sql = "SELECT * FROM `$table_log` $where ORDER BY click_id DESC LIMIT $per_page OFFSET $offset";
    $logs = $db->fetchObjects( $sql, $binds );

    $nonce = yourls_create_nonce( 'mlv_db_nonce' );
    
    // Style sheets for table
    echo <<<HTML
    <style>
        .mlv-container {
            margin-top: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .mlv-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .mlv-search-box {
            display: flex;
            gap: 10px;
        }
        .mlv-search-box input[type="text"] {
            padding: 8px 12px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            min-width: 250px;
        }
        .mlv-table-wrapper {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .mlv-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .mlv-table th {
            background: #f9fafb;
            padding: 12px 16px;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }
        .mlv-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #4b5563;
            vertical-align: top;
        }
        .mlv-table tr:hover td {
            background: #f9fafb;
        }
        .mlv-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: #e5e7eb;
            color: #374151;
        }
        .mlv-badge-geo {
            background: #d1fae5;
            color: #065f46;
        }
        .mlv-badge-device {
            background: #dbeafe;
            color: #1e40af;
        }
        .mlv-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        .mlv-btn {
            padding: 8px 16px;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            color: #374151;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        .mlv-btn:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        .mlv-btn.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>

    <div class="mlv-container">
        <h2>Modern Clicks Log Viewer</h2>
        
        <div class="mlv-header">
            <div>
                <strong>GeoLite2 City Database:</strong> $db_status_html
                <form method="post" style="display:inline; margin-left: 10px;">
                    <input type="hidden" name="nonce" value="$nonce" />
                    <input type="submit" name="mlv_update_db" class="button" value="Update DB" />
                </form>
            </div>
            
            <div class="mlv-search-box">
                <form method="get" action="">
                    <input type="hidden" name="page" value="modern_log_viewer" />
                    <input type="text" name="s" placeholder="Search keyword, IP, UA, or referrer..." value="{$search}" />
                    <input type="submit" class="button button-primary" value="Search" />
                    <?php if (!empty($search)): ?>
                        <a href="?page=modern_log_viewer" class="mlv-btn" style="padding: 5px 10px; margin-left:5px;">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="mlv-table-wrapper">
            <table class="mlv-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Keyword</th>
                        <th>IP Address</th>
                        <th>Location</th>
                        <th>Browser / OS</th>
                        <th>Device</th>
                        <th>Referrer</th>
                    </tr>
                </thead>
                <tbody>
HTML;

    if ( $logs ) {
        foreach ( $logs as $log ) {
            $click_time = date( 'Y-m-d H:i:s', strtotime( $log->click_time ) );
            $keyword = htmlspecialchars( $log->shorturl );
            $ip = htmlspecialchars( $log->ip_address );
            $referrer = $log->referrer == 'direct' ? '<span style="color:#9ca3af;">Direct</span>' : '<a href="' . htmlspecialchars($log->referrer) . '" target="_blank" style="word-break: break-all; font-size:12px;">' . htmlspecialchars(substr($log->referrer, 0, 80)) . (strlen($log->referrer) > 80 ? '...' : '') . '</a>';
            
            // Resolve Location via MaxMind GeoLite2
            $country = 'Unknown';
            $city = '';
            if ( $reader && filter_var( $log->ip_address, FILTER_VALIDATE_IP ) ) {
                try {
                    $record = $reader->get( $log->ip_address );
                    if ( isset( $record['country']['names']['en'] ) ) {
                        $country = $record['country']['names']['en'];
                    }
                    if ( isset( $record['city']['names']['en'] ) ) {
                        $city = $record['city']['names']['en'];
                    }
                } catch ( Exception $e ) {}
            }
            $location_str = !empty( $city ) ? "$city, $country" : $country;
            $location_badge = $country !== 'Unknown' 
                ? '<span class="mlv-badge mlv-badge-geo">' . htmlspecialchars( $location_str ) . '</span>'
                : '<span class="mlv-badge">' . htmlspecialchars( $location_str ) . '</span>';

            // Parse User Agent via WhichBrowser
            $browser_desc = 'Unknown';
            $os_desc = 'Unknown';
            $device_type = 'Unknown';
            try {
                $wb = new Parser( $log->user_agent );
                if ( $wb->browser->name ) {
                    $browser_desc = $wb->browser->toString();
                }
                if ( $wb->os->name ) {
                    $os_desc = $wb->os->toString();
                }
                if ( $wb->device->type ) {
                    $device_type = ucfirst( $wb->device->type );
                }
            } catch ( Exception $e ) {}

            $device_badge = $device_type !== 'Unknown'
                ? '<span class="mlv-badge mlv-badge-device">' . htmlspecialchars( $device_type ) . '</span>'
                : '<span class="mlv-badge">' . htmlspecialchars( $device_type ) . '</span>';

            echo <<<HTML
            <tr>
                <td style="white-space: nowrap;">$click_time</td>
                <td><strong style="color:#4f46e5;">$keyword</strong></td>
                <td><a href="https://ipinfo.io/$ip" target="_blank">$ip</a></td>
                <td>$location_badge</td>
                <td>
                    <strong>$browser_desc</strong><br>
                    <span style="font-size: 12px; color:#9ca3af;">$os_desc</span>
                </td>
                <td>$device_badge</td>
                <td>$referrer</td>
            </tr>
HTML;
        }
    } else {
        echo '<tr><td colspan="7" style="text-align:center; padding: 20px;">No click logs found.</td></tr>';
    }

    echo <<<HTML
                </tbody>
            </table>
        </div>
HTML;

    // Render Pagination
    if ( $total_pages > 1 ) {
        $prev_class = $page <= 1 ? 'disabled' : '';
        $next_class = $page >= $total_pages ? 'disabled' : '';
        
        $search_query = !empty($search) ? '&s=' . urlencode($search) : '';
        
        $prev_link = "?page=modern_log_viewer&pg=" . ($page - 1) . $search_query;
        $next_link = "?page=modern_log_viewer&pg=" . ($page + 1) . $search_query;

        echo <<<HTML
        <div class="mlv-pagination">
            <a href="$prev_link" class="mlv-btn $prev_class">&larr; Previous</a>
            <span>Page <strong>$page</strong> of <strong>$total_pages</strong> ($total_clicks total clicks)</span>
            <a href="$next_link" class="mlv-btn $next_class">Next &rarr;</a>
        </div>
HTML;
    }

    echo '</div>';
}
