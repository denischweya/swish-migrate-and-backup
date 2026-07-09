<?php
/**
 * Admin Layout Component
 *
 * Provides the main layout wrapper with top navigation
 * for all Pro plugin admin pages.
 *
 * @package SwishMigrateAndBackup\Admin
 */

declare(strict_types=1);

namespace SwishMigrateAndBackup\Admin\Multisite;

/**
 * AdminLayout class for rendering the modern admin UI wrapper.
 */
final class AdminLayout {

    /**
     * Get the current theme preference.
     *
     * @return string 'light' or 'dark'
     */
    public static function get_theme(): string {
        $user_id = get_current_user_id();
        $theme   = get_user_meta( $user_id, 'swish_backup_theme', true );

        if ( ! $theme ) {
            $theme = 'light';
        }

        return $theme;
    }

    /**
     * Render the opening layout wrapper with top navigation.
     *
     * @param string $page_slug Current page slug for active nav highlighting.
     * @return void
     */
    public static function render_start( string $page_slug = '' ): void {
        $theme = self::get_theme();
        ?>
        <div class="swish-app swish-app-top-nav" data-theme="<?php echo esc_attr( $theme ); ?>">
            <?php self::render_top_nav( $page_slug ); ?>
            <main class="swish-main">
        <?php
    }

    /**
     * Render the closing layout wrapper.
     *
     * @return void
     */
    public static function render_end(): void {
        ?>
            </main>
        </div>
        <?php
    }

    /**
     * Render the top navigation bar.
     *
     * @param string $active_slug Active page slug.
     * @return void
     */
    private static function render_top_nav( string $active_slug = '' ): void {
        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $current_tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

        $nav_items = array(
            array(
                'slug'  => 'swish-backup-multisite',
                'label' => __( 'Dashboard', 'swish-migrate-and-backup' ),
                'icon'  => 'dashboard',
            ),
            array(
                'slug'  => 'swish-backup-multisite',
                'tab'   => 'backup',
                'label' => __( 'Create Backup', 'swish-migrate-and-backup' ),
                'icon'  => 'backup',
            ),
            array(
                'slug'  => 'swish-backup-migration',
                'label' => __( 'Migration', 'swish-migrate-and-backup' ),
                'icon'  => 'swap_horiz',
            ),
            array(
                'slug'  => 'swish-backup-duplicate',
                'label' => __( 'Duplicate', 'swish-migrate-and-backup' ),
                'icon'  => 'content_copy',
            ),
            array(
                'slug'  => 'swish-backup-multisite',
                'tab'   => 'history',
                'label' => __( 'History', 'swish-migrate-and-backup' ),
                'icon'  => 'history',
            ),
        );
        ?>
        <header class="swish-top-nav">
            <div class="swish-top-nav-brand">
                <span class="material-symbols-outlined" style="color: var(--swish-primary-600);">security</span>
                <h1><?php esc_html_e( 'Swish Backup', 'swish-migrate-and-backup' ); ?></h1>
                <span class="swish-pro-tag"><?php esc_html_e( 'Multisite', 'swish-migrate-and-backup' ); ?></span>
            </div>

            <nav class="swish-top-nav-menu">
                <?php foreach ( $nav_items as $item ) : ?>
                    <?php
                    $is_active = $current_page === $item['slug'];
                    if ( isset( $item['tab'] ) ) {
                        $is_active = $is_active && $current_tab === $item['tab'];
                    } elseif ( $is_active && $current_tab ) {
                        $is_active = false;
                    }

                    $url = is_network_admin()
                        ? network_admin_url( 'admin.php?page=' . $item['slug'] )
                        : admin_url( 'admin.php?page=' . $item['slug'] );

                    if ( isset( $item['tab'] ) ) {
                        $url = add_query_arg( 'tab', $item['tab'], $url );
                    }
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="swish-top-nav-item <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="material-symbols-outlined"><?php echo esc_html( $item['icon'] ); ?></span>
                        <span><?php echo esc_html( $item['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="swish-top-nav-actions">
                <?php self::render_theme_toggle(); ?>
            </div>
        </header>
        <?php
    }

    /**
     * Render the page header section.
     *
     * @param string $title       Page title.
     * @param string $description Optional page description.
     * @param array  $actions     Optional action buttons.
     * @return void
     */
    public static function render_header( string $title, string $description = '', array $actions = [] ): void {
        ?>
        <div class="swish-content">
            <div class="swish-content-inner">
                <div class="swish-page-header">
                    <h3><?php echo esc_html( $title ); ?></h3>
                    <?php if ( $description ) : ?>
                        <p><?php echo esc_html( $description ); ?></p>
                    <?php endif; ?>
                </div>
        <?php
    }

    /**
     * Render the page content closing tags.
     *
     * @return void
     */
    public static function render_content_end(): void {
        ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the theme toggle button.
     *
     * @return void
     */
    private static function render_theme_toggle(): void {
        ?>
        <button type="button" class="swish-theme-toggle" id="swish-theme-toggle" aria-label="Toggle dark mode">
            <span class="material-symbols-outlined swish-icon-sun">light_mode</span>
            <span class="material-symbols-outlined swish-icon-moon">dark_mode</span>
        </button>
        <?php
    }

    /**
     * Render a card component.
     *
     * @param string   $title   Card title.
     * @param callable $content Callback to render card content.
     * @param array    $footer  Optional footer content.
     * @return void
     */
    public static function render_card( string $title, callable $content, array $footer = [] ): void {
        ?>
        <div class="swish-card">
            <?php if ( $title ) : ?>
                <div class="swish-card-header">
                    <h4 style="margin: 0; font-size: var(--swish-text-lg); font-weight: var(--swish-font-semibold);">
                        <?php echo esc_html( $title ); ?>
                    </h4>
                </div>
            <?php endif; ?>
            <div class="swish-card-body">
                <?php call_user_func( $content ); ?>
            </div>
            <?php if ( ! empty( $footer ) ) : ?>
                <div class="swish-card-footer">
                    <?php
                    if ( is_callable( $footer ) ) {
                        call_user_func( $footer );
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the filter bar section.
     *
     * @param array $options Filter options configuration.
     * @return void
     */
    public static function render_filter_bar( array $options = [] ): void {
        $defaults = array(
            'search_placeholder' => __( 'Search...', 'swish-migrate-and-backup' ),
            'show_search'        => true,
            'show_status_filter' => true,
            'statuses'           => array(
                ''           => __( 'All Statuses', 'swish-migrate-and-backup' ),
                'success'    => __( 'Success', 'swish-migrate-and-backup' ),
                'failed'     => __( 'Failed', 'swish-migrate-and-backup' ),
                'in_progress' => __( 'In Progress', 'swish-migrate-and-backup' ),
            ),
            'action_button'      => null,
        );

        $options = wp_parse_args( $options, $defaults );
        ?>
        <div class="swish-filter-bar">
            <?php if ( $options['show_search'] ) : ?>
                <div class="swish-filter-search">
                    <div class="swish-search-input">
                        <span class="material-symbols-outlined" style="color: var(--swish-text-muted);">search</span>
                        <input type="text" id="swish-search" placeholder="<?php echo esc_attr( $options['search_placeholder'] ); ?>">
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $options['show_status_filter'] ) : ?>
                <div class="swish-filter-select">
                    <div class="swish-select-wrapper">
                        <span class="material-symbols-outlined" style="color: var(--swish-text-muted); font-size: 18px;">filter_list</span>
                        <select id="swish-status-filter">
                            <?php foreach ( $options['statuses'] as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $options['action_button'] ) : ?>
                <div class="swish-filter-action">
                    <button type="button" class="swish-btn swish-btn-primary" id="<?php echo esc_attr( $options['action_button']['id'] ?? 'swish-action-btn' ); ?>">
                        <?php if ( isset( $options['action_button']['icon'] ) ) : ?>
                            <span class="material-symbols-outlined" style="font-size: 18px;"><?php echo esc_html( $options['action_button']['icon'] ); ?></span>
                        <?php endif; ?>
                        <span><?php echo esc_html( $options['action_button']['label'] ); ?></span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the stats cards grid.
     *
     * @param array $stats Array of stat configurations.
     * @return void
     */
    public static function render_stats_grid( array $stats ): void {
        ?>
        <div class="swish-stats-grid">
            <?php foreach ( $stats as $stat ) : ?>
                <div class="swish-stat-card">
                    <span class="swish-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
                    <div class="swish-stat-content">
                        <div>
                            <p class="swish-stat-value"><?php echo esc_html( $stat['value'] ); ?></p>
                            <?php if ( isset( $stat['description'] ) ) : ?>
                                <p class="swish-stat-description <?php echo isset( $stat['description_class'] ) ? esc_attr( $stat['description_class'] ) : ''; ?>">
                                    <?php echo esc_html( $stat['description'] ); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if ( isset( $stat['icon'] ) ) : ?>
                            <span class="material-symbols-outlined swish-stat-icon <?php echo isset( $stat['icon_class'] ) ? esc_attr( $stat['icon_class'] ) : ''; ?>">
                                <?php echo esc_html( $stat['icon'] ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render an alert/notice.
     *
     * @param string $type    Alert type: success, warning, danger, info.
     * @param string $title   Alert title.
     * @param string $message Alert message.
     * @return void
     */
    public static function render_alert( string $type, string $title = '', string $message = '' ): void {
        $icons = array(
            'success' => 'check_circle',
            'warning' => 'warning',
            'error'   => 'error',
            'danger'  => 'error',
            'info'    => 'info',
        );

        $icon = $icons[ $type ] ?? 'info';
        ?>
        <div class="swish-alert swish-alert-<?php echo esc_attr( $type ); ?>">
            <span class="material-symbols-outlined swish-alert-icon"><?php echo esc_html( $icon ); ?></span>
            <div class="swish-alert-content">
                <?php if ( $title ) : ?>
                    <div class="swish-alert-title"><?php echo esc_html( $title ); ?></div>
                <?php endif; ?>
                <div class="swish-alert-message"><?php echo wp_kses_post( $message ); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render an empty state.
     *
     * @param string $title       Empty state title.
     * @param string $description Empty state description.
     * @param string $icon        Material icon name.
     * @param array  $action      Optional action button config.
     * @return void
     */
    public static function render_empty_state( string $title, string $description, string $icon = 'inbox', array $action = [] ): void {
        ?>
        <div class="swish-empty-state">
            <span class="material-symbols-outlined swish-empty-state-icon"><?php echo esc_html( $icon ); ?></span>
            <h4 class="swish-empty-state-title"><?php echo esc_html( $title ); ?></h4>
            <p class="swish-empty-state-description"><?php echo esc_html( $description ); ?></p>
            <?php if ( ! empty( $action ) ) : ?>
                <button type="button" class="swish-btn swish-btn-primary" id="<?php echo esc_attr( $action['id'] ?? '' ); ?>">
                    <?php if ( isset( $action['icon'] ) ) : ?>
                        <span class="material-symbols-outlined" style="font-size: 18px;"><?php echo esc_html( $action['icon'] ); ?></span>
                    <?php endif; ?>
                    <span><?php echo esc_html( $action['label'] ); ?></span>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }
}
