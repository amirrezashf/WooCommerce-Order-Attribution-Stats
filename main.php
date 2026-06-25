<?php
/**
 * Plugin Name: WooCommerce Order Attribution Stats
 * Plugin URI: https://github.com/amirrezashf/woocommerce-order-attribution-stats
 * Description: Displays WooCommerce Order Attribution origin statistics with fixed and custom date ranges, pie charts, revenue, average order value, and 24-hour caching.
 * Version: 1.0.0
 * Author: Amirreza Shayesteh Far
 * Author URI: https://amirrezaa.ir/
 * License: GPL v2 or later
 * Text Domain: woocommerce-order-attribution-stats
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WCOAS_Order_Attribution_Stats {

	const MENU_SLUG = 'wcoas-order-attribution-stats';
	const CACHE_TTL = DAY_IN_SECONDS;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_refresh_cache' ) );
	}

	public function add_menu() {
		add_menu_page(
			'آمار انتساب سفارش',
			'آمار انتساب سفارش',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-pie',
			56
		);
	}

	public function maybe_refresh_cache() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( empty( $_GET['wcoas_refresh_attr_stats'] ) ) {
			return;
		}

		check_admin_referer( 'wcoas_refresh_attr_stats_action' );

		delete_transient( $this->get_cache_key() );

		wp_safe_redirect(
			remove_query_arg(
				array( 'wcoas_refresh_attr_stats', '_wpnonce' ),
				admin_url( 'admin.php?page=' . self::MENU_SLUG )
			)
		);
		exit;
	}

	private function get_cache_key() {
		return 'wcoas_order_attr_stats_v100_' . get_current_blog_id();
	}

	private function get_ranges() {
		return array(
			30  => '۳۰ روز اخیر',
			90  => '۹۰ روز اخیر',
			180 => '۱۸۰ روز اخیر',
			360 => '۳۶۰ روز اخیر',
		);
	}

	private function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	private function get_colors() {
		return array(
			'#6d28d9',
			'#2563eb',
			'#0f766e',
			'#ea580c',
			'#be185d',
			'#475569',
			'#7c3aed',
			'#0891b2',
			'#16a34a',
			'#b45309',
		);
	}

	private function get_current_wp_timestamp() {
		return current_time( 'timestamp' );
	}

	private function format_money( $amount ) {
		$amount = (float) $amount;

		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return number_format_i18n( $amount );
	}

	private function build_origin_label( $source_type, $utm_source ) {
		$source_type = is_scalar( $source_type ) ? trim( (string) $source_type ) : '';
		$utm_source  = is_scalar( $utm_source ) ? trim( (string) $utm_source ) : '';

		switch ( $source_type ) {
			case 'utm':
				return '' !== $utm_source ? 'Source: ' . ucfirst( trim( $utm_source, '()' ) ) : 'Source';

			case 'organic':
				return '' !== $utm_source ? 'Organic: ' . ucfirst( trim( $utm_source, '()' ) ) : 'Organic';

			case 'referral':
				return '' !== $utm_source ? 'Referral: ' . ucfirst( trim( $utm_source, '()' ) ) : 'Referral';

			case 'typein':
				return 'Direct';

			case 'mobile_app':
				return 'Mobile app';

			case 'admin':
				return 'Web admin';

			case 'pos':
				return 'Point of Sale';

			default:
				return 'Unknown';
		}
	}

	private function get_cached_orders() {
		$cache_key = $this->get_cache_key();
		$data      = get_transient( $cache_key );

		if ( is_array( $data ) && isset( $data['orders'] ) && is_array( $data['orders'] ) ) {
			return $data;
		}

		$orders = $this->load_orders_with_attribution_meta();

		$data = array(
			'generated_at' => $this->get_current_wp_timestamp(),
			'orders'       => $orders,
		);

		set_transient( $cache_key, $data, self::CACHE_TTL );

		return $data;
	}

	private function load_orders_with_attribution_meta() {
		global $wpdb;

		$max_days   = 360;
		$cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $max_days * DAY_IN_SECONDS ) );

		if ( $this->is_hpos_enabled() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$meta_table   = $wpdb->prefix . 'wc_orders_meta';

			$sql = "
				SELECT
					o.id AS order_id,
					o.date_created_gmt AS date_created_gmt,
					o.total_amount AS order_total,
					st.meta_value AS source_type,
					us.meta_value AS utm_source
				FROM {$orders_table} o
				LEFT JOIN {$meta_table} st
					ON st.order_id = o.id AND st.meta_key = '_wc_order_attribution_source_type'
				LEFT JOIN {$meta_table} us
					ON us.order_id = o.id AND us.meta_key = '_wc_order_attribution_utm_source'
				WHERE o.type = 'shop_order'
					AND o.status NOT IN ('trash', 'auto-draft', 'draft')
					AND o.date_created_gmt >= %s
				ORDER BY o.id DESC
			";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cutoff_gmt ), ARRAY_A );
		} else {
			$sql = "
				SELECT
					p.ID AS order_id,
					p.post_date_gmt AS date_created_gmt,
					ot.meta_value AS order_total,
					st.meta_value AS source_type,
					us.meta_value AS utm_source
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} ot
					ON ot.post_id = p.ID AND ot.meta_key = '_order_total'
				LEFT JOIN {$wpdb->postmeta} st
					ON st.post_id = p.ID AND st.meta_key = '_wc_order_attribution_source_type'
				LEFT JOIN {$wpdb->postmeta} us
					ON us.post_id = p.ID AND us.meta_key = '_wc_order_attribution_utm_source'
				WHERE p.post_type = 'shop_order'
					AND p.post_status NOT IN ('trash', 'auto-draft', 'draft')
					AND p.post_date_gmt >= %s
				ORDER BY p.ID DESC
			";

			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $cutoff_gmt ), ARRAY_A );
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$orders = array();

		foreach ( $rows as $row ) {
			$order_id = ! empty( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;

			if ( ! $order_id ) {
				continue;
			}

			$gmt = ! empty( $row['date_created_gmt'] ) ? $row['date_created_gmt'] : '';

			if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
				continue;
			}

			$timestamp_local = absint( get_date_from_gmt( $gmt, 'U' ) );

			if ( ! $timestamp_local ) {
				continue;
			}

			$source_type = isset( $row['source_type'] ) ? (string) $row['source_type'] : '';
			$utm_source  = isset( $row['utm_source'] ) ? (string) $row['utm_source'] : '';
			$order_total = isset( $row['order_total'] ) ? (float) $row['order_total'] : 0.0;

			$orders[] = array(
				'order_id'        => $order_id,
				'timestamp_local' => $timestamp_local,
				'order_total'     => $order_total,
				'source_type'     => $source_type,
				'utm_source'      => $utm_source,
				'has_attribution' => ( '' !== $source_type || '' !== $utm_source ),
				'origin'          => $this->build_origin_label( $source_type, $utm_source ),
			);
		}

		return $orders;
	}

	private function build_stats_for_period( $orders, $start_ts, $end_ts, $label, $days = 0 ) {
		$origin_stats       = array();
		$total_orders       = 0;
		$orders_with_attr   = 0;
		$orders_without     = 0;
		$total_revenue      = 0.0;

		foreach ( $orders as $order ) {
			$ts = isset( $order['timestamp_local'] ) ? (int) $order['timestamp_local'] : 0;

			if ( ! $ts || $ts < $start_ts || $ts > $end_ts ) {
				continue;
			}

			$total_orders++;

			$order_total    = isset( $order['order_total'] ) ? (float) $order['order_total'] : 0.0;
			$total_revenue += $order_total;

			if ( ! empty( $order['has_attribution'] ) ) {
				$orders_with_attr++;
			} else {
				$orders_without++;
			}

			$origin = ! empty( $order['origin'] ) ? $order['origin'] : 'Unknown';

			if ( ! isset( $origin_stats[ $origin ] ) ) {
				$origin_stats[ $origin ] = array(
					'count'   => 0,
					'revenue' => 0.0,
					'avg'     => 0.0,
				);
			}

			$origin_stats[ $origin ]['count']++;
			$origin_stats[ $origin ]['revenue'] += $order_total;
		}

		foreach ( $origin_stats as $origin => $stat ) {
			$origin_stats[ $origin ]['avg'] = $stat['count'] > 0 ? ( $stat['revenue'] / $stat['count'] ) : 0.0;
		}

		uasort(
			$origin_stats,
			function ( $a, $b ) {
				if ( $a['count'] === $b['count'] ) {
					return $b['revenue'] <=> $a['revenue'];
				}

				return $b['count'] <=> $a['count'];
			}
		);

		return array(
			'label'               => $label,
			'days'                => $days,
			'start_ts'            => $start_ts,
			'end_ts'              => $end_ts,
			'total_orders'        => $total_orders,
			'orders_with_attr'    => $orders_with_attr,
			'orders_without_attr' => $orders_without,
			'total_revenue'       => $total_revenue,
			'overall_avg'         => $total_orders > 0 ? ( $total_revenue / $total_orders ) : 0.0,
			'origins'             => $origin_stats,
		);
	}

	private function build_fixed_range_stats( $orders ) {
		$ranges = $this->get_ranges();
		$now    = $this->get_current_wp_timestamp();
		$stats  = array();

		foreach ( $ranges as $days => $label ) {
			$start_ts       = $now - ( absint( $days ) * DAY_IN_SECONDS );
			$stats[ $days ] = $this->build_stats_for_period( $orders, $start_ts, $now, $label, $days );
		}

		return $stats;
	}

	private function get_custom_range_stats( $orders ) {
		$start_raw = isset( $_GET['wcoas_start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wcoas_start_date'] ) ) : '';
		$end_raw   = isset( $_GET['wcoas_end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wcoas_end_date'] ) ) : '';

		if ( '' === $start_raw || '' === $end_raw ) {
			return array(
				'has_filter' => false,
				'start_raw'  => $start_raw,
				'end_raw'    => $end_raw,
				'stats'      => null,
				'error'      => '',
			);
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_raw ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_raw ) ) {
			return array(
				'has_filter' => true,
				'start_raw'  => $start_raw,
				'end_raw'    => $end_raw,
				'stats'      => null,
				'error'      => 'فرمت تاریخ نامعتبر است.',
			);
		}

		try {
			$start_dt = new DateTimeImmutable( $start_raw . ' 00:00:00', wp_timezone() );
			$end_dt   = new DateTimeImmutable( $end_raw . ' 23:59:59', wp_timezone() );
		} catch ( Exception $e ) {
			return array(
				'has_filter' => true,
				'start_raw'  => $start_raw,
				'end_raw'    => $end_raw,
				'stats'      => null,
				'error'      => 'تاریخ واردشده معتبر نیست.',
			);
		}

		$start_ts = $start_dt->getTimestamp();
		$end_ts   = $end_dt->getTimestamp();

		if ( $start_ts > $end_ts ) {
			return array(
				'has_filter' => true,
				'start_raw'  => $start_raw,
				'end_raw'    => $end_raw,
				'stats'      => null,
				'error'      => 'تاریخ شروع نباید از تاریخ پایان بزرگ‌تر باشد.',
			);
		}

		return array(
			'has_filter' => true,
			'start_raw'  => $start_raw,
			'end_raw'    => $end_raw,
			'stats'      => $this->build_stats_for_period( $orders, $start_ts, $end_ts, 'بازه سفارشی', 0 ),
			'error'      => '',
		);
	}

	private function build_chart_parts( $origins ) {
		$total = 0;

		foreach ( $origins as $stat ) {
			$total += isset( $stat['count'] ) ? (int) $stat['count'] : 0;
		}

		if ( $total <= 0 ) {
			return array(
				'gradient' => '#eef2ff 0 100%',
				'legend'   => array(),
			);
		}

		$colors = $this->get_colors();
		$start  = 0;
		$parts  = array();
		$legend = array();
		$index  = 0;

		foreach ( $origins as $label => $stat ) {
			$count   = isset( $stat['count'] ) ? (int) $stat['count'] : 0;
			$revenue = isset( $stat['revenue'] ) ? (float) $stat['revenue'] : 0.0;
			$avg     = isset( $stat['avg'] ) ? (float) $stat['avg'] : 0.0;
			$percent = $total > 0 ? ( $count / $total ) * 100 : 0;
			$end     = $start + $percent;
			$color   = $colors[ $index % count( $colors ) ];

			$parts[] = sprintf( '%s %.2f%% %.2f%%', $color, $start, $end );

			$legend[] = array(
				'label'   => $label,
				'count'   => $count,
				'percent' => round( $percent, 1 ),
				'revenue' => $revenue,
				'avg'     => $avg,
				'color'   => $color,
			);

			$start = $end;
			$index++;
		}

		return array(
			'gradient' => implode( ', ', $parts ),
			'legend'   => $legend,
		);
	}

	private function render_stats_section( $range, $custom_range_text = '' ) {
		$origins       = ! empty( $range['origins'] ) ? $range['origins'] : array();
		$chart         = $this->build_chart_parts( $origins );
		$total_orders  = ! empty( $range['total_orders'] ) ? (int) $range['total_orders'] : 0;
		$with_attr     = ! empty( $range['orders_with_attr'] ) ? (int) $range['orders_with_attr'] : 0;
		$without_attr  = ! empty( $range['orders_without_attr'] ) ? (int) $range['orders_without_attr'] : 0;
		$total_revenue = ! empty( $range['total_revenue'] ) ? (float) $range['total_revenue'] : 0.0;
		$overall_avg   = ! empty( $range['overall_avg'] ) ? (float) $range['overall_avg'] : 0.0;
		?>
		<section class="wcoas-section">
			<div class="wcoas-section-head">
				<div class="wcoas-head-right">
					<h2><?php echo esc_html( $range['label'] ); ?></h2>

					<?php if ( ! empty( $range['days'] ) ) : ?>
						<span class="wcoas-badge"><?php echo esc_html( $range['days'] ); ?> روز</span>
					<?php endif; ?>

					<?php if ( $custom_range_text ) : ?>
						<span class="wcoas-badge wcoas-badge-soft"><?php echo esc_html( $custom_range_text ); ?></span>
					<?php endif; ?>
				</div>

				<div class="wcoas-summary-cards">
					<div class="wcoas-summary purple"><span>کل سفارش‌ها</span><strong><?php echo esc_html( number_format_i18n( $total_orders ) ); ?></strong></div>
					<div class="wcoas-summary blue"><span>دارای انتساب</span><strong><?php echo esc_html( number_format_i18n( $with_attr ) ); ?></strong></div>
					<div class="wcoas-summary slate"><span>بدون انتساب</span><strong><?php echo esc_html( number_format_i18n( $without_attr ) ); ?></strong></div>
					<div class="wcoas-summary teal"><span>فروش کل</span><strong><?php echo esc_html( $this->format_money( $total_revenue ) ); ?></strong></div>
					<div class="wcoas-summary orange"><span>میانگین کل سفارش</span><strong><?php echo esc_html( $this->format_money( $overall_avg ) ); ?></strong></div>
				</div>
			</div>

			<div class="wcoas-section-body">
				<div class="wcoas-chart-panel">
					<div class="wcoas-pie" style="background: conic-gradient(<?php echo esc_attr( $chart['gradient'] ); ?>);">
						<div class="wcoas-pie-inner">
							<strong><?php echo esc_html( number_format_i18n( $total_orders ) ); ?></strong>
							<small>سفارش</small>
						</div>
					</div>
				</div>

				<div class="wcoas-table-panel">
					<?php if ( ! empty( $chart['legend'] ) ) : ?>
						<table class="widefat fixed striped wcoas-table">
							<thead>
								<tr>
									<th>Origin</th>
									<th>تعداد سفارش</th>
									<th>درصد</th>
									<th>مبلغ فروش</th>
									<th>میانگین مبلغ سفارش</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $chart['legend'] as $item ) : ?>
									<tr>
										<td>
											<div class="wcoas-origin-cell">
												<span class="wcoas-dot" style="background: <?php echo esc_attr( $item['color'] ); ?>;"></span>
												<span><?php echo esc_html( $item['label'] ); ?></span>
											</div>
										</td>
										<td><?php echo esc_html( number_format_i18n( $item['count'] ) ); ?></td>
										<td><?php echo esc_html( $item['percent'] ); ?>٪</td>
										<td><?php echo esc_html( $this->format_money( $item['revenue'] ) ); ?></td>
										<td><?php echo esc_html( $this->format_money( $item['avg'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<div class="wcoas-empty">داده‌ای برای نمایش وجود ندارد.</div>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.' );
		}

		$cached       = $this->get_cached_orders();
		$orders       = ! empty( $cached['orders'] ) ? (array) $cached['orders'] : array();
		$generated_at = ! empty( $cached['generated_at'] ) ? absint( $cached['generated_at'] ) : 0;
		$wp_now       = $this->get_current_wp_timestamp();

		$fixed_stats = $this->build_fixed_range_stats( $orders );
		$custom_data = $this->get_custom_range_stats( $orders );

		$refresh_url = wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&wcoas_refresh_attr_stats=1' ),
			'wcoas_refresh_attr_stats_action'
		);
		?>
		<div class="wrap wcoas-wrap">
			<h1>آمار انتساب سفارش ووکامرس</h1>

			<div class="wcoas-top">
				<div class="wcoas-note">
					<div><strong>منبع داده:</strong> Order Attribution ووکامرس</div>
					<div><strong>مبنای تفکیک:</strong> Origin</div>
					<div><strong>زمان فعلی وردپرس:</strong> <?php echo esc_html( wp_date( 'Y/m/d - H:i', $wp_now, wp_timezone() ) ); ?></div>
					<div><strong>آخرین بروزرسانی کش:</strong> <?php echo $generated_at ? esc_html( wp_date( 'Y/m/d - H:i', $generated_at, wp_timezone() ) ) : '---'; ?></div>
					<div><strong>مدت کش:</strong> ۲۴ ساعت</div>
					<div><strong>تعداد سفارشات خوانده‌شده:</strong> <?php echo esc_html( number_format_i18n( count( $orders ) ) ); ?></div>
				</div>

				<a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-primary">بروزرسانی دستی</a>
			</div>

			<div class="wcoas-sections">
				<?php foreach ( $fixed_stats as $range ) : ?>
					<?php $this->render_stats_section( $range ); ?>
				<?php endforeach; ?>
			</div>

			<div class="wcoas-custom-box">
				<div class="wcoas-custom-head">
					<h2>بازه زمانی سفارشی</h2>
					<p>تاریخ شروع و پایان را به میلادی مشخص کن تا آمار و نمودار همان بازه نمایش داده شود.</p>
				</div>

				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wcoas-custom-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">

					<div class="wcoas-field">
						<label for="wcoas-start-date">تاریخ شروع</label>
						<input type="date" id="wcoas-start-date" name="wcoas_start_date" value="<?php echo esc_attr( $custom_data['start_raw'] ); ?>">
					</div>

					<div class="wcoas-field">
						<label for="wcoas-end-date">تاریخ پایان</label>
						<input type="date" id="wcoas-end-date" name="wcoas_end_date" value="<?php echo esc_attr( $custom_data['end_raw'] ); ?>">
					</div>

					<div class="wcoas-actions">
						<button type="submit" class="button button-primary">نمایش آمار</button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">پاک کردن بازه</a>
					</div>
				</form>

				<?php if ( ! empty( $custom_data['error'] ) ) : ?>
					<div class="notice notice-error inline wcoas-inline-notice">
						<p><?php echo esc_html( $custom_data['error'] ); ?></p>
					</div>
				<?php endif; ?>

				<?php
				if ( ! empty( $custom_data['has_filter'] ) && empty( $custom_data['error'] ) && ! empty( $custom_data['stats'] ) ) {
					$this->render_stats_section( $custom_data['stats'], $custom_data['start_raw'] . ' تا ' . $custom_data['end_raw'] );
				}
				?>
			</div>
		</div>

		<?php $this->render_inline_styles(); ?>
		<?php
	}

	private function render_inline_styles() {
		?>
		<style>
			.wcoas-wrap{direction:rtl}
			.wcoas-wrap *{box-sizing:border-box}
			.wcoas-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:18px 0 22px}
			.wcoas-note{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px 16px;line-height:1.95;box-shadow:0 10px 28px rgba(15,23,42,.05)}
			.wcoas-sections{display:flex;flex-direction:column;gap:18px;margin-bottom:20px}
			.wcoas-section,.wcoas-custom-box{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}
			.wcoas-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
			.wcoas-head-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
			.wcoas-head-right h2,.wcoas-custom-head h2{margin:0;font-size:20px;line-height:1.4}
			.wcoas-custom-head{margin-bottom:16px}
			.wcoas-custom-head p{margin:8px 0 0;color:#64748b}
			.wcoas-badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:999px;background:#f3e8ff;color:#6d28d9;font-size:12px;font-weight:700;white-space:nowrap}
			.wcoas-badge-soft{background:#eff6ff;color:#2563eb}
			.wcoas-summary-cards{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:10px;flex:1;min-width:min(100%,780px)}
			.wcoas-summary{border-radius:14px;padding:12px 14px;color:#111827;border:1px solid transparent;min-height:76px}
			.wcoas-summary span{display:block;font-size:12px;margin-bottom:8px;opacity:.9}
			.wcoas-summary strong{display:block;font-size:20px;line-height:1.25;font-weight:800;word-break:break-word}
			.wcoas-summary.purple{background:#faf5ff;border-color:#eadcff}
			.wcoas-summary.blue{background:#eff6ff;border-color:#dbeafe}
			.wcoas-summary.slate{background:#f8fafc;border-color:#e2e8f0}
			.wcoas-summary.teal{background:#f0fdfa;border-color:#ccfbf1}
			.wcoas-summary.orange{background:#fff7ed;border-color:#fed7aa}
			.wcoas-section-body{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:start}
			.wcoas-chart-panel{display:flex;align-items:center;justify-content:center;padding:6px 0}
			.wcoas-pie{width:190px;height:190px;border-radius:50%;position:relative}
			.wcoas-pie-inner{position:absolute;inset:24px;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;box-shadow:0 0 0 1px #eef2f7 inset;text-align:center}
			.wcoas-pie-inner strong{font-size:24px;line-height:1.1;font-weight:800;color:#111827}
			.wcoas-pie-inner small{margin-top:4px;font-size:12px;color:#64748b}
			.wcoas-table-panel{min-width:0}
			.wcoas-table{border-radius:14px;overflow:hidden;border:1px solid #edf2f7;box-shadow:none}
			.wcoas-table thead th{background:#f8fafc!important;font-weight:700;font-size:12px;color:#475569}
			.wcoas-table th,.wcoas-table td{padding:12px 10px!important;vertical-align:middle}
			.wcoas-origin-cell{display:flex;align-items:center;gap:8px;min-width:0}
			.wcoas-dot{width:12px;height:12px;border-radius:50%;flex:0 0 12px}
			.wcoas-empty{padding:14px;border:1px dashed #d1d5db;border-radius:12px;background:#fafafa;color:#6b7280}
			.wcoas-custom-form{display:grid;grid-template-columns:repeat(3,minmax(180px,240px));gap:14px;align-items:end;margin-top:10px}
			.wcoas-field label{display:block;margin-bottom:8px;font-weight:600;color:#334155}
			.wcoas-field input[type="date"]{width:100%;height:42px;padding:0 12px;border:1px solid #dbe2ea;border-radius:12px;background:#fff;outline:none;box-shadow:none}
			.wcoas-field input[type="date"]:focus{border-color:#6d28d9;box-shadow:0 0 0 3px rgba(109,40,217,.08)}
			.wcoas-actions{display:flex;gap:10px;flex-wrap:wrap}
			.wcoas-inline-notice{margin:16px 0 0!important;border-radius:12px;overflow:hidden}
			@media (max-width:1200px){.wcoas-summary-cards{grid-template-columns:repeat(3,minmax(160px,1fr))}}
			@media (max-width:900px){.wcoas-section-body{grid-template-columns:1fr}.wcoas-summary-cards{grid-template-columns:repeat(2,minmax(160px,1fr));min-width:100%}.wcoas-custom-form{grid-template-columns:1fr}}
			@media (max-width:640px){.wcoas-summary-cards{grid-template-columns:1fr}}
		</style>
		<?php
	}
}

new WCOAS_Order_Attribution_Stats();
