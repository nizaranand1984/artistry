<?php
class DLK_Builder_Module_Course_Content extends DLK_Builder_Module_Template {
	function init() {
		$this->name       = esc_html__( 'Course Content', 'et_builder' );
		$this->slug       = 'et_pb_ld_course_content';
		$this->fb_support = false;

		$this->whitelisted_fields = array(
			'title',
			'course_id',
			'background_layout',
			'admin_label',
			'module_id',
			'module_class',
		);

		$this->fields_defaults = array(
			'background_layout' => array( 'light' ),
			'course_id' => array( 'all' ),
		);

		$this->main_css_element = '%%order_class%%.et_pb_ld_course_content';

		$this->options_toggles = array(
			'general'  => array(
				'toggles' => array(
					'main_content' => esc_html__( 'General', 'et_builder' ),
				),
			),
			'advanced' => array(
				'toggles' => array(
					'text' => array(
						'title'    => esc_html__( 'General', 'et_builder' ),
						'priority' => 49,
					),
				),
			),
		);

		$this->advanced_options = array(
			'fonts' => array(
				'title' => array(
					'label'    => esc_html__( 'Title', 'et_builder' ),
					'css'      => array(
						'main'      => "{$this->main_css_element} h3",
						'important' => 'plugin_only',
					),
				),
				'table_heading' => array(
					'label'    => esc_html__( 'Table Heading', 'et_builder' ),
					'css'      => array(
						'main'      => "{$this->main_css_element} #lesson_heading, {$this->main_css_element} #quiz_heading, {$this->main_css_element} #topic_heading",
						'important' => 'plugin_only',
					),
				),
				'sub_table_row' => array(
					'label'    => esc_html__( 'Sub Table Row', 'et_builder' ),
					'css'      => array(
						'main'      => "{$this->main_css_element} .topic_item a span",
						'important' => 'plugin_only',
					),
				),
				'table_row' => array(
					'label'    => esc_html__( 'Table Row', 'et_builder' ),
					'css'      => array(
						'main'      => "{$this->main_css_element} .list-count, {$this->main_css_element} #lessons_list h4 > a, {$this->main_css_element} #quiz_list h4 > a, {$this->main_css_element} #topic_list h4 > a",
						'important' => 'plugin_only',
					),
				),
				'expand_collapse' => array(
					'label'    => esc_html__( 'Expand / Collapse', 'et_builder' ),
					'css'      => array(
						'main'      => "{$this->main_css_element} .expand_collapse, {$this->main_css_element} .expand_collapse a",
						'important' => 'plugin_only',
					),
				),
			),
			'background' => array(
				'settings' => array(
					'color' => 'alpha',
				),
			),
			'border' => array(),
			'custom_margin_padding' => array(
				'use_margin' => false,
				'css' => array(
					'important' => 'all',
				),
			),
		);

		if ( et_is_builder_plugin_active() ) {
			$this->advanced_options['fonts']['number']['css']['important'] = 'all';
		}
	}

	function get_fields() {
	
		$fields = array(
			'title' => array(
				'label'           => esc_html__( 'Title', 'et_builder' ),
				'type'            => 'text',
				'option_category' => 'basic_option',
				'description'     => esc_html__( 'Input a title for the module.', 'et_builder' ),
				'toggle_slug'     => 'main_content',
			),
			'course_id' => array(
				'label'           => esc_html__( 'Course', 'et_builder' ),
				'type'            => 'select',
				'option_category' => 'basic_option',
				'options'         => dlk_get_course_select_options(),
				'description'     => esc_html__( 'Show course content for selected course.', 'et_builder' ),
				'toggle_slug'     => 'main_content',
				'default' => 'all'
			),
			
			'background_layout' => array(
				'label'           => esc_html__( 'Text Color', 'et_builder' ),
				'type'            => 'select',
				'option_category' => 'color_option',
				'options'         => array(
					'light' => esc_html__( 'Dark', 'et_builder' ),
					'dark'  => esc_html__( 'Light', 'et_builder' ),
				),
				'tab_slug'        => 'advanced',
				'toggle_slug'     => 'text',
				'description'     => esc_html__( 'Here you can choose whether your title text should be light or dark. If you are working with a dark background, then your text should be light. If your background is light, then your text should be set to dark.', 'et_builder' ),
			),
			'disabled_on' => array(
				'label'           => esc_html__( 'Disable on', 'et_builder' ),
				'type'            => 'multiple_checkboxes',
				'options'         => array(
					'phone'   => esc_html__( 'Phone', 'et_builder' ),
					'tablet'  => esc_html__( 'Tablet', 'et_builder' ),
					'desktop' => esc_html__( 'Desktop', 'et_builder' ),
				),
				'additional_att'  => 'disable_on',
				'option_category' => 'configuration',
				'description'     => esc_html__( 'This will disable the module on selected devices', 'et_builder' ),
				'tab_slug'        => 'custom_css',
				'toggle_slug'     => 'visibility',
			),
			'admin_label' => array(
				'label'       => esc_html__( 'Admin Label', 'et_builder' ),
				'type'        => 'text',
				'description' => esc_html__( 'This will change the label of the module in the builder for easy identification.', 'et_builder' ),
				'toggle_slug' => 'admin_label',
			),
			'module_id' => array(
				'label'           => esc_html__( 'CSS ID', 'et_builder' ),
				'type'            => 'text',
				'option_category' => 'configuration',
				'tab_slug'        => 'custom_css',
				'toggle_slug'     => 'classes',
				'option_class'    => 'et_pb_custom_css_regular',
			),
			'module_class' => array(
				'label'           => esc_html__( 'CSS Class', 'et_builder' ),
				'type'            => 'text',
				'option_category' => 'configuration',
				'tab_slug'        => 'custom_css',
				'toggle_slug'     => 'classes',
				'option_class'    => 'et_pb_custom_css_regular',
			),
		);
		return $fields;
	}

	function shortcode_callback( $atts, $content = null, $function_name ) {

		$title             = $this->shortcode_atts['title'];
		$course_id             = $this->shortcode_atts['course_id'];
		$module_id         = $this->shortcode_atts['module_id'];
		$module_class      = $this->shortcode_atts['module_class'];
		$background_layout = $this->shortcode_atts['background_layout'];

		$module_class = ET_Builder_Element::add_module_order_class( $module_class, $function_name );

		$video_background = $this->video_background();
		$parallax_image_background = $this->get_parallax_image_background();

		$classes = esc_attr(implode(' ', array(
			'et_pb_module', 
			'et_pb_ld_module', 
			'et_pb_ld_course_content',
			"et_pb_bg_layout_{$background_layout}",
			$module_class,
			('' !== $video_background ? 'et_pb_section_video et_pb_preload' : ''),
			('' !== $parallax_image_background ? 'et_pb_section_parallax' : '')
		)));
	
		$id = ( '' !== $module_id ? sprintf( ' id="%1$s"', esc_attr( $module_id ) ) : '' );
		$title = ( '' !== $title ? '<h3>' . esc_html( $title ) . '</h3>' : '' );
		
		$course_id = ($course_id === 'all')?'':' course_id="'.esc_html($course_id).'"';
		
		$shortcode = "[course_content{$course_id}]";

		$shortcode_output = do_shortcode($shortcode);
		
		$output = "<div{$id} class=\"{$classes}\">{$video_background} {$parallax_image_background} {$title}  {$shortcode_output} </div>";

		return $output;
	}
}
new DLK_Builder_Module_Course_Content;