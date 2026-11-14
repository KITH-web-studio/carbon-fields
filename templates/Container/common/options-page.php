<?php
$container_id = $this->get_id();
$sidebar_sections = $this->get_sidebar_sections();
$settings_panel = $this->uses_settings_panel();
if ( ! isset( $container_css_class ) ) {
	$container_css_class = 'generic-container';
}
?>
<div class="wrap carbon-<?php echo $container_css_class; ?><?php if ( $sidebar_sections ) { echo ' carbon-options-with-sidebar'; } ?><?php if ( $settings_panel ) { echo ' cf-settings-page'; } ?>">
	<?php if ( $settings_panel ) : ?>
		<h1><?php echo esc_html( $this->title ); ?></h1>
	<?php else : ?>
		<h2><?php echo $this->title ?></h2>
	<?php endif; ?>
	<?php if ( $this->page_description ) : ?>
		<p class="carbon-options-description description"><?php echo esc_html( $this->page_description ); ?></p>
	<?php endif; ?>

	<?php if ( $this->errors ) :  ?>
		<div class="error settings-error">
			<?php foreach ( $this->errors as $error ) :  ?>
				<p><strong><?php echo $error; ?></strong></p>
			<?php endforeach ?>
		</div>
	<?php elseif ( $this->notifications && ! $settings_panel ) :  ?>
		<?php foreach ( $this->notifications as $notification ) :  ?>
			<div class="settings-error updated">
				<p><strong><?php echo $notification ?></strong></p>
			</div>
		<?php endforeach ?>
	<?php endif; ?>

	<form method="post" id="<?php echo $container_css_class; ?>-form" enctype="multipart/form-data" action=""<?php if ( $settings_panel ) { echo ' novalidate'; } ?>>
		<?php
		$filter_name = 'carbon_fields_' . str_replace( '-', '_', sanitize_title( $this->title ) ) . '_button_label';
		$button_label = apply_filters( $filter_name, __( 'Save Changes', 'carbon-fields' ) );
		?>
		<?php if ( $settings_panel ) : ?>
			<div class="cf-settings-toolbar">
				<?php do_action( "{$container_id}_before_fields" ); ?>
				<div id="<?php echo esc_attr( $container_id . '-toolbar' ); ?>" class="cf-settings-toolbar__mount" data-save-label="<?php echo esc_attr( $button_label ); ?>"></div>
			</div>
		<?php endif; ?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">

					<?php if ( ! $settings_panel ) { do_action( "{$container_id}_before_fields" ); } ?>

					<div class="postbox carbon-box" id="<?php echo $this->get_id(); ?>">
						<fieldset class="inside <?php echo $container_css_class; ?>-container container-<?php echo $this->get_id(); ?>"></fieldset>
					</div>

					<?php do_action( "{$container_id}_after_fields" ); ?>
				</div>

				<div id="postbox-container-1" class="postbox-container">

					<?php do_action( "{$container_id}_before_sidebar" ); ?>

					<?php foreach ( $sidebar_sections as $index => $section ) : ?>
						<?php $section_id = $container_id . '-sidebar-' . $index; ?>
						<section class="postbox carbon-box carbon-options-panel" aria-labelledby="<?php echo esc_attr( $section_id . '-title' ); ?>">
							<div class="postbox-header">
								<h2 class="hndle" id="<?php echo esc_attr( $section_id . '-title' ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
							</div>
							<div class="inside" id="<?php echo esc_attr( $section_id ); ?>"></div>
						</section>
					<?php endforeach; ?>

					<?php if ( ! $settings_panel ) : ?>
					<div id="submitdiv" class="postbox">
						<h3><?php _e( 'Actions', 'carbon-fields' ); ?></h3>

						<div id="major-publishing-actions">

							<div id="publishing-action">
								<span class="spinner"></span>

								<input type="submit" value="<?php echo esc_attr( $button_label ); ?>" name="publish" id="publish" class="button button-primary button-large">
							</div>

							<div class="clear"></div>
						</div>
					</div>
					<?php endif; ?>

					<?php do_action( "{$container_id}_after_sidebar" ); ?>

				</div>
			</div>
		</div>
		<?php if ( $this->requires_complete_editor_form() ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $container_id . '_options_complete' ); ?>" value="1">
		<?php endif; ?>
	</form>
</div>
