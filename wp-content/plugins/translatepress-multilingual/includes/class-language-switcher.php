<?php

/**
 * Class TRP_Language_Switcher
 *
 * Generates all types of language switchers.
 */
class TRP_Language_Switcher{

    protected $settings;
	/** @var TRP_Url_Converter */
    protected $url_converter;
    protected $trp_settings_object;
	/** @var TRP_Languages */
    protected $trp_languages;
	/** @var TRP_Translate_Press */
    protected $trp;

    /**
     * TRP_Language_Switcher constructor.
     *
     * @param array $settings           Settings option.
     * @param $trp TRP_Translate_Press  Trp object
     */
    public function __construct( $settings, $trp ){
        $this->settings = $settings;
        $this->trp = $trp;
        $this->url_converter = $this->trp->get_component( 'url_converter' );
        $language = $this->get_current_language($trp);
        global $TRP_LANGUAGE;
        $TRP_LANGUAGE = $language;
        add_filter( 'get_user_option_metaboxhidden_nav-menus', array( $this, 'cpt_always_visible_in_menus' ), 10, 3 );
    }

	/**
     * Returns a valid current language code.
     *
     * Adds filter for redirect if necessary
     *
	 * @param $trp TRP_Translate_Press  TRP singleton object
	 *
	 * @return string       Language code
	 */
	private function get_current_language( $trp ){
		$language_from_url = $this->url_converter->get_lang_from_url_string();

		$needed_language = $this->determine_needed_language( $language_from_url, $trp );

		$allow_redirect = apply_filters( 'trp_allow_language_redirect', true, $needed_language );
		if ( $allow_redirect ) {
			if ( ( $language_from_url == null && isset( $this->settings['add-subdirectory-to-default-language'] ) && $this->settings['add-subdirectory-to-default-language'] == 'yes' ) ||
			     ( $language_from_url == null && $needed_language != $this->settings['default-language'] ) ||
			     ( $language_from_url != null && $needed_language != $language_from_url )
			) {
                global $TRP_NEEDED_LANGUAGE;
                $TRP_NEEDED_LANGUAGE = $needed_language;
                add_filter( 'template_redirect', array( $this, 'redirect_to_correct_language' ) );
			}
		}

        return $needed_language;
	}

	/**
     * Determine the language needed.
     *
	 * @param string $lang_from_url          Language code from url
	 * @param TRP_Translate_Press $trp       TRP singleton object
	 *
	 * @return string Language code
	 */
	public function determine_needed_language( $lang_from_url, $trp ){
		if ( $lang_from_url == null ){
			if ( isset( $this->settings['add-subdirectory-to-default-language'] ) && $this->settings['add-subdirectory-to-default-language'] == 'yes' && isset( $this->settings['publish-languages'][0] ) ) {
                $needed_language = $this->settings['publish-languages'][0];
			}else{
				$needed_language = $this->settings['default-language'];
			}
		}else{
			$needed_language = $lang_from_url;
		}
		return apply_filters( 'trp_needed_language', $needed_language, $lang_from_url, $this->settings, $trp );
    }

	/**
	 * Redirects to language stored in global $TRP_NEEDED_LANGUAGE
	 */
	public function redirect_to_correct_language(){
	    if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX  ) || is_customize_preview() ) {
			return;
		}
		global $TRP_NEEDED_LANGUAGE;
		if ( ! $this->url_converter ){
			$trp = TRP_Translate_Press::get_trp_instance();
			$this->trp_languages = $trp->get_component( 'url_converter' );
		}
        $link_to_redirect = apply_filters( 'trp_link_to_redirect_to', $this->url_converter->get_url_for_language( $TRP_NEEDED_LANGUAGE, null, '' ), $TRP_NEEDED_LANGUAGE );
		header( 'Location: ' . $link_to_redirect );
		exit;
    }

	/**
	 * Returns HTML for shortcode language switcher.
	 *
	 * Only shows published languages.
	 * Takes into account shortcode flags and name options.
	 * Runs an output buffer on 'partials/language-switcher-shortcode.php'.
	 *
	 * @return string                   HTML for shortcode language switcher
	 */
	public function language_switcher(){
		ob_start();

		global $TRP_LANGUAGE;

		if ( ! $this->trp_languages ){
			$trp = TRP_Translate_Press::get_trp_instance();
			$this->trp_languages = $trp->get_component( 'languages' );
		}
		$published_languages = $this->trp_languages->get_language_names( $this->settings['publish-languages'] );

		$current_language = array();
		$other_languages = array();

		foreach( $published_languages as $code => $name ) {
			if( $code == $TRP_LANGUAGE ) {
				$current_language['code'] = $code;
				$current_language['name'] = $name;
			} else {
				$other_languages[$code] = $name;
			}
		}

		if( ! $this->trp_settings_object ) {
			$trp = TRP_Translate_Press::get_trp_instance();
			$this->trp_settings_object = $trp->get_component( 'settings' );
		}
		$ls_options = $this->trp_settings_object->get_language_switcher_options();
		$shortcode_settings = $ls_options[$this->settings['shortcode-options']];

		require TRP_PLUGIN_DIR . 'partials/language-switcher-shortcode.php';

		return ob_get_clean();
	}

    /**
     * Enqueue language switcher scripts and styles.
     *
     * Adds scripts for shortcode and floater.
     *
     * Hooked on wp_enqueue_scripts.
     */
    public function enqueue_language_switcher_scripts( ){

        if ( isset( $this->settings['trp-ls-floater'] ) && $this->settings['trp-ls-floater'] == 'yes' ) {
            wp_enqueue_style('trp-floater-language-switcher-style', TRP_PLUGIN_URL . 'assets/css/trp-floater-language-switcher.css', array(), TRP_PLUGIN_VERSION );
        }

        wp_enqueue_style( 'trp-language-switcher-style', TRP_PLUGIN_URL . 'assets/css/trp-language-switcher.css', array(), TRP_PLUGIN_VERSION );
    }

    /**
     * Adds the floater language switcher.
     *
     * Hooked on wp_footer.
     */
    public function add_floater_language_switcher() {

        // Check if floater language switcher is active and return if not
        if( $this->settings['trp-ls-floater'] != 'yes' ) {
            return;
        }

        if ( ! $this->trp_settings_object ) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $this->trp_settings_object = $trp->get_component( 'settings' );
        }

        // Current language
        global $TRP_LANGUAGE;

        // All the published languages
        if ( ! $this->trp_languages ){
            $trp = TRP_Translate_Press::get_trp_instance();
            $this->trp_languages = $trp->get_component( 'languages' );
        }
        $published_languages = $this->trp_languages->get_language_names( $this->settings['publish-languages'] );

        // Floater languages display defaults
        $floater_class = 'trp-floater-ls-names';
        $floater_flags_class = '';

        // Floater languages settings
        $ls_options = $this->trp_settings_object->get_language_switcher_options();
        $floater_settings = $ls_options[$this->settings['floater-options']];

        if( $floater_settings['full_names'] ) {
            $floater_class  = 'trp-floater-ls-names';
        }

        if( $floater_settings['short_names'] ) {
            $floater_class  = 'trp-floater-ls-codes';
        }

        if( $floater_settings['flags'] && ! $floater_settings['full_names'] && ! $floater_settings['short_names'] ) {
            $floater_class  = 'trp-floater-ls-flags';
        }

        if( $floater_settings['flags'] && ( $floater_settings['full_names'] || $floater_settings['short_names'] ) ) {
            $floater_flags_class = 'trp-with-flags';
        }

        $current_language = array();
        $other_languages = array();

        foreach( $published_languages as $code => $name ) {
            if( $code == $TRP_LANGUAGE ) {
                $current_language['code'] = $code;
                $current_language['name'] = $name;
            } else {
                $other_languages[$code] = $name;
            }
        }

        $current_language_label = '';

        if( $floater_settings['full_names'] ) {
            $current_language_label = ucfirst( $current_language['name'] );
        }

        if( $floater_settings['short_names'] ) {
            $current_language_label = strtoupper( $this->url_converter->get_url_slug( $current_language['code'], false ) );
        }

        ?>
        <div id="trp-floater-ls" onclick="" data-no-translation class="trp-language-switcher-container <?php echo esc_attr( $floater_class ); ?>" <?php echo ( isset( $_GET['trp-edit-translation'] ) && $_GET['trp-edit-translation'] == 'preview' ) ? 'data-trp-unpreviewable="trp-unpreviewable"' : '' ?>>
            <div id="trp-floater-ls-current-language" class="<?php echo esc_attr( $floater_flags_class ); ?>">
                <a href="javascript:void(0)" class="trp-floater-ls-disabled-language trp-ls-disabled-language" onclick="void(0)">
					<?php echo ( $floater_settings['flags'] ? $this->add_flag( $current_language['code'], $current_language['name'] ) : '' ); // WPCS: ok.
					echo esc_html( $current_language_label ); ?>
				</a>
            </div>
            <div id="trp-floater-ls-language-list" class="<?php echo esc_attr( $floater_flags_class );?>" <?php echo ( isset( $_GET['trp-edit-translation'] ) && $_GET['trp-edit-translation'] == 'preview' ) ? 'data-trp-unpreviewable="trp-unpreviewable"' : ''?>>
                <?php
                foreach( $other_languages as $code => $name ) {
                    $language_label = '';

                    if( $floater_settings['full_names'] ) {
                        $language_label = ucfirst( $name );
                    }

                    if( $floater_settings['short_names'] ) {
                        $language_label = strtoupper( $this->url_converter->get_url_slug( $code, false ) );
                    }

                    ?>
                    <a href="<?php echo esc_url( $this->url_converter->get_url_for_language($code, false) ); ?>" <?php echo ( isset( $_GET['trp-edit-translation'] ) && $_GET['trp-edit-translation'] == 'preview' ) ? 'data-trp-unpreviewable="trp-unpreviewable"' : '' ?> title="<?php echo esc_attr( $name ); ?>">
						<?php echo ( $floater_settings['flags'] ? $this->add_flag( $code, $name ) : '' ); // WPCS: ok.
						echo esc_html( $language_label ); ?>
					</a>
                <?php
                }
                ?>
				<a href="javascript:void(0)"
				   class="trp-floater-ls-disabled-language trp-ls-disabled-language">
					<?php
						echo ( $floater_settings['flags'] ? $this->add_flag( $current_language['code'], $current_language['name'] ) : '' ); // WPCS: ok.
						echo esc_html( $current_language_label );
					?>
				</a>
            </div>
        </div>

    <?php
    }

    /**
     * Return flag html.
     *
     * @param string $language_code         Language code.
     * @param string $language_name         Language full name or shortname.
     * @param string $location              NULL | ls_shortcode
     * @return string                       Returns flag html.
     */
    public function add_flag( $language_code, $language_name, $location = NULL ) {

        // Path to folder with flags images
        $flags_path = TRP_PLUGIN_URL .'assets/images/flags/';
        $flags_path = apply_filters( 'trp_flags_path', $flags_path, $language_code );

        // File name for specific flag
        $flag_file_name = $language_code .'.png';
        $flag_file_name = apply_filters( 'trp_flag_file_name', $flag_file_name, $language_code );

        // HTML code to display flag image
        $flag_html = '<img class="trp-flag-image" src="'. esc_url( $flags_path . $flag_file_name ) .'" width="18" height="12" alt="' . esc_attr( $language_code ) . '" title="' . esc_attr( $language_name ) . '">';

        if( $location == 'ls_shortcode' ) {
            $flag_url = $flags_path . $flag_file_name;
            return esc_url( $flag_url );
        }

        return $flag_html;
    }

    /**
     * Return full or short name, with or without flag
     *
     * @param string $language_code         Language code.
     * @param string $language_name         Language full name or shortname.
     * @param array $settings              NULL | ls_shortcode
     * @return string                       Returns html with flags short or long names, depending on settings.
     */
    public function add_shortcode_preferences( $settings, $language_code, $language_name ) {
        if ( $settings['flags'] ){
            $flag = $this->add_flag($language_code, $language_name);
        } else {
            $flag = '';
        }

        if ( $settings['full_names'] ){
            $full_name = $language_name;
        } else {
            $full_name = '';
        }

        if ( $settings['short_names'] ){
            $short_name = strtoupper( $this->url_converter->get_url_slug( $language_code, false ) );
        } else {
            $short_name = '';
        }

        return $flag . ' ' . esc_html( $short_name . $full_name );
    }

    /**
     * Register language switcher post type.
     *
     */
    public function register_ls_menu_switcher( ){
        $args = array(
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'show_ui'               => true,
            'show_in_nav_menus'     => true,
            'show_in_menu'          => false,
            'show_in_admin_bar'     => false,
            'can_export'            => false,
            'public'                => false,
            'label'                 => 'Language Switcher'
        );
        register_post_type( 'language_switcher', $args );
    }

    /**
     * Makes the Language Switcher CPT always visible in Menus interface.
     *
     */
    function cpt_always_visible_in_menus( $result, $option, $user )
    {
        if( in_array( 'add-post-type-language_switcher', $result ) )
            $result = array_diff( $result, array( 'add-post-type-language_switcher' ) );

        return $result;
    }

    /**
     * Prepare language switcher menu items.
     *
     * Sets the current page permalinks to menu items.
     * Inserts flags and full name if necessary
     * Removes menu item of current language if Current Language item is present.
     *
     * Hooked on wp_get_nav_menu_items
     *
     * @param array $items          Menu items.
     * @param string $menu          Menu name.
     * @param array $args           Menu arguments.
     * @return array                Menu items with
     */
    public function ls_menu_permalinks( $items, $menu, $args ){
        global $TRP_LANGUAGE;
        if ( ! $this->trp_settings_object ) {
            $trp = TRP_Translate_Press::get_trp_instance();
            $this->trp_settings_object = $trp->get_component( 'settings' );
        }
        if ( ! $this->trp_languages ){
            $trp = TRP_Translate_Press::get_trp_instance();
            $this->trp_languages = $trp->get_component( 'languages' );
        }

        $item_key_to_unset = false;
        $current_language_set = false;
        foreach ( $items as $key => $item ){
            if ( $item->object == 'language_switcher' ){
                $ls_id = get_post_meta( $item->ID, '_menu_item_object_id', true );
                $ls_post = get_post( $ls_id );
                if ( $ls_post == null || $ls_post->post_type != 'language_switcher' ) {
                    continue;
                }
                $ls_options = $this->trp_settings_object->get_language_switcher_options();
                $menu_settings = $ls_options[$this->settings['menu-options']];
                $language_code = $ls_post->post_content;

                if ( $language_code == $TRP_LANGUAGE && ! is_admin() ){
                    $item_key_to_unset = $key;
                }

                if ( $language_code == 'current_language' ) {
                    $language_code = $TRP_LANGUAGE;
                    $current_language_set = true;
                }
                $language_names = $this->trp_languages->get_language_names( array( $language_code ) );
                $language_name = $language_names[$language_code];


                $items[$key]->url = $this->url_converter->get_url_for_language( $language_code );
                $items[$key]->classes[] = 'trp-language-switcher-container';
                $items[$key]->title = '<span data-no-translation>';
                if ( $menu_settings['flags'] ) {
                    $items[$key]->title .= $this->add_flag( $language_code, $language_name );
                }
                if ( $menu_settings['short_names'] ) {
                    $items[$key]->title .= '<span class="trp-ls-language-name">' . strtoupper( $this->url_converter->get_url_slug( $language_code, false ) ) . '</span>';
                }
                if ( $menu_settings['full_names'] ) {
                    $items[$key]->title .= '<span class="trp-ls-language-name">' . $language_name . '</span>';
                }
                $items[$key]->title .= '</span>';

                $items[$key]->title = apply_filters( 'trp_menu_language_switcher', $items[$key]->title, $language_name, $language_code, $menu_settings );
            }
        }

        // removes menu item of current language if "Current Language" language switcher item is present.
        if ( $current_language_set && $item_key_to_unset ){
            unset($items[$item_key_to_unset]);
            $items = array_values( $items );
        }


        return $items;
    }


}
