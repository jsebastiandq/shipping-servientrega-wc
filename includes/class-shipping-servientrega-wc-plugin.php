<?php
/**
 * Created by PhpStorm.
 * User: smp
 * Date: 26/03/19
 * Time: 05:47 PM
 */

class Shipping_Servientrega_WC_Plugin
{

    /**
     * Filepath of main plugin file.
     *
     * @var string
     */
    public $file;
    /**
     * Plugin version.
     *
     * @var string
     */
    public $version;
    /**
     * Absolute plugin path.
     *
     * @var string
     */
    public $plugin_path;
    /**
     * Absolute plugin URL.
     *
     * @var string
     */
    public $plugin_url;
    /**
     * assets plugin.
     *
     * @var string
     */
    public $assets;
    /**
     * Absolute path to plugin includes dir.
     *
     * @var string
     */
    public $includes_path;
    /**
     * Absolute path to plugin lib dir
     *
     * @var string
     */
    public $lib_path;
    /**
     * @var bool
     */
    private $_bootstrapped = false;

    public function __construct($file, $version)
    {
        $this->file = $file;
        $this->version = $version;

        $this->plugin_path   = trailingslashit( plugin_dir_path( $this->file ) );
        $this->plugin_url    = trailingslashit( plugin_dir_url( $this->file ) );
        $this->assets = $this->plugin_url . trailingslashit('assets');
        $this->includes_path = $this->plugin_path . trailingslashit( 'includes' );
        $this->lib_path = $this->plugin_path . trailingslashit( 'lib' );
    }

    public function run_servientrega_wc()
    {
        try{
            if ($this->_bootstrapped){
                throw new Exception( 'Servientrega shipping can only be called once');
            }
            $this->_run();
            $this->_bootstrapped = true;
        }catch (Exception $e){
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                add_action('admin_notices', function() use($e) {
                    shipping_servientrega_wc_ss_notices($e->getMessage());
                });
            }
        }
    }

    protected function _run()
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Reader\Xls'))
            require_once ($this->lib_path . 'vendor/autoload.php');
        if (!class_exists('\WebService\Servientrega'))
            require_once ($this->lib_path . 'servientrega-webservice-php/src/WebService.php');
        require_once ($this->includes_path . 'class-method-shipping-servientrega-wc.php');
        require_once ($this->includes_path . 'class-shipping-servientrega-wc.php');
    
        add_filter( 'plugin_action_links_' . plugin_basename( $this->file), array( $this, 'plugin_action_links' ) );
        add_filter( 'woocommerce_shipping_methods', array( $this, 'shipping_servientrega_wc_add_method') );
        add_action( 'woocommerce_order_status_changed', array('Shipping_Servientrega_WC', 'generate_guide'), 20, 4 );
        add_action( 'woocommerce_process_product_meta', array($this, 'save_custom_shipping_option_to_products') );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_scripts_admin') );
        add_action( 'wp_ajax_servientrega_shipping_matriz',array($this, 'servientrega_shipping_matriz'));
    }

    public function plugin_action_links($links)
    {
        $plugin_links = array();
        $plugin_links[] = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=shipping_servientrega_wc') . '">' . 'Configuraciones' . '</a>';
        $plugin_links[] = '<a href="https://saulmoralespa.github.io/shipping-servientrega-wc/">' . 'Documentación' . '</a>';
        return array_merge( $plugin_links, $links );
    }

    public function shipping_servientrega_wc_add_method( $methods )
    {
        $methods['shipping_servientrega_wc'] = 'WC_Shipping_Method_Shipping_Servientrega_WC';
        return $methods;
    }

    public function log($message)
    {
        if (is_array($message) || is_object($message))
            $message = print_r($message, true);
        $logger = new WC_Logger();
        $logger->add('shipping-servientrega', $message);
    }

    public static function add_custom_shipping_option_to_products()
    {
        global $post;

        woocommerce_wp_text_input( [
            'id'          => '_shipping_custom_price_product_smp',
            'label'       => __( 'Valor declarado del producto'),
            'placeholder' => 'Valor declarado del envío',
            'desc_tip'    => true,
            'description' => __( 'El valor que desea declarar para el envío'),
            'value'       => get_post_meta( $post->ID, '_shipping_custom_price_product_smp', true )
        ] );
    }

    public function save_custom_shipping_option_to_products($post_id)
    {
        $custom_price_product = $_POST['_shipping_custom_price_product_smp'];
        if( isset( $custom_price_product ) )
            update_post_meta( $post_id, '_shipping_custom_price_product_smp', esc_attr( $custom_price_product ) );
    }

    public static function createTable()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'shipping_servientrega_matriz';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) === $table_name )
            return;

        $sql = "CREATE TABLE $table_name (
		id_ciudad_destino INT NOT NULL,
		tiempo_entrega_comercial FLOAT(2,0) NOT NULL,
		tipo_trayecto VARCHAR(30) NOT NULL,
		restriccion_fisica VARCHAR(60),
		PRIMARY KEY  (id_ciudad_destino)
	) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function enqueue_scripts_admin($hook)
    {
        if ($hook !== 'woocommerce_page_wc-settings') return;

        wp_enqueue_script( 'shipping_servientrega_wc_ss', shipping_servientrega_wc_ss()->plugin_url . 'assets/js/config.js', array( 'jquery' ), shipping_servientrega_wc_ss()->version, true );
    }

    public function servientrega_shipping_matriz()
    {
        $dir = $this->pathUpload();

        $fileName = $_FILES["servientrega_xls"]["name"];
        $fileTmpName = $_FILES["servientrega_xls"]["tmp_name"];

        $name = $this->changeName($fileName);

        $pathXLS = $dir . $name;

        $result = [
            'status' => true
        ];

        $wc_main_settings = get_option('woocommerce_servientrega_shipping_settings');
        $wc_main_settings['shipping_servientrega_matriz'] = true;

        if (!move_uploaded_file($fileTmpName, $pathXLS))
            wp_send_json($result);

        try{
            $reader = new PhpOffice\PhpSpreadsheet\Reader\Xls();
            $spreadsheet = $reader->load($pathXLS);
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $data = array_shift($rows);

            if (count($data) !== 11){
                $result = [
                    'status' => false,
                    'message' => 'El excel debe tener 11 columnas de A - K'
                ];

                wp_send_json($result);
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'shipping_servientrega_matriz';
            $sql = "DELETE FROM $table_name";
            $wpdb->query($sql);

            self::createTable();

            foreach ($rows as $column){

                $wpdb->insert(
                    $table_name,
                    [
                        'id_ciudad_destino' => (int)$column['D'],
                        'tiempo_entrega_comercial' => $column['I'],
                        'tipo_trayecto' => $this->singleWord($column['J']),
                        'restriccion_fisica' => $this->convertJson($column['K'])
                    ]
                );
            }
        }catch (\Exception $exception){
            $result = [
                'status' => false,
                'message' => $exception->getMessage()
            ];
            $wc_main_settings['shipping_servientrega_matriz'] = '';
        }


        update_option('woocommerce_servientrega_shipping_settings', $wc_main_settings);

        wp_send_json($result);

    }

    public function pathUpload()
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']);
    }

    public function changeName($file)
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $name = "matrix.$extension";

        return $name;
    }

    public function convertJson($column)
    {
        $words= strtolower($column);
        $words= preg_replace('/\s+/', '', $words);
        $words= str_replace(['kgs', 'cms'], '|', $words);
        $words= explode( '|', $words);
        $words= array_filter($words, 'strlen');

        $data = [];

        foreach( $words as $word ){
            $tmp = explode( ':', $word );
            $data[ $tmp[0] ] = $tmp[1];
        }

        return json_encode($data);

    }

    public function singleWord($column)
    {
        $word = strtolower($column);
        if(strpos($word, ' ') !== false){
            $word = explode(' ', $word);
            $word = $word[1];
        }

        return $word;
    }
}