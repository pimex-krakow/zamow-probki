<?php
// Isolated unit/contract checks, not a WordPress integration test.
define('ABSPATH', __DIR__);define('DAY_IN_SECONDS',86400);
function remove_accents($s) {return strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);}
function wp_strip_all_tags($s) {return strip_tags($s);}
$GLOBALS['opts']=[];
function get_option($k,$default=false){return $GLOBALS['opts'][$k] ?? $default;}
function sanitize_title($s){return trim(preg_replace('/[^a-z0-9]+/','-',strtolower(remove_accents($s))),'-');}
function wc_attribute_label($n,$p){return $n;}
function taxonomy_exists($n){return !empty($GLOBALS['element_taxonomy']) && $n==='pa_typ-elementu';}
function get_the_terms($id,$taxonomy){return $GLOBALS['element_terms'][$id]??false;}
function get_term_by($field,$value,$taxonomy){return $value==='deska-tarasowa'?(object)['name'=>'Deska tarasowa']:false;}
function get_post_meta($id,$key,$single){return isset($GLOBALS['test_products'][$id]) ? $GLOBALS['test_products'][$id]->get_meta($key) : '';}
function wp_get_attachment_image_url($id,$size){return 'product.jpg';}
function wc_placeholder_img_src(){return 'placeholder.jpg';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_ancestors($id,$type,$t){return [];}
function get_term($id,$type){return (object)['slug'=>$id===2?'deski-kompozytowe':'deski-drewniane'];}
function is_wp_error($x){return false;}
function add_action(...$a){$GLOBALS['hooks'][]=$a;}
require __DIR__.'/../../pimex-probki-v5/pimex-probki-v5.php';
function check($ok,$label){if(!$ok){throw new Exception($label);} echo "PASS $label\n";}
class Product {
 public $attrs; public $category; public $id=1; public $meta=[];
 function __construct($attrs=[],$category=2){$this->attrs=$attrs;$this->category=$category;}
 function get_attributes(){return $this->attrs;} function get_price(){return '123';}
 function get_attribute($n){$value=$this->attrs[$n]??'';if(is_object($value)){return method_exists($value,'get_terms')?implode(', ',array_column($value->get_terms(),'name')):(method_exists($value,'get_options')?implode(', ',$value->get_options()):'');}return $value;}
 function get_category_ids(){return [$this->category];}
 function get_name(){return 'Model A';}
 function get_sku(){return 'SKU';}
 function get_id(){return $this->id;}
 function get_status(){return 'publish';}
 function get_catalog_visibility(){return 'visible';}
 function get_type(){return 'simple';}
 function is_type($type){return $type==='simple';}
 function get_meta($key){return $this->meta[$key]??'';}
 function get_image_id(){return 1;}
 function get_permalink($id=null){return $id && isset($GLOBALS['permalinks'][$id]) ? $GLOBALS['permalinks'][$id] : '#';}
 function is_purchasable(){return true;}
 function is_in_stock(){return true;}
 public $stored_price; public $regular_price; public $sale_price; public $stored_status;
 function get_tax_status(){return 'taxable';}
 function get_tax_class(){return '';}
 function set_sold_individually($v){}
 function set_regular_price($v){$this->regular_price=$v;}
 function set_sale_price($v){$this->sale_price=$v;}
 function set_price($v){$this->stored_price=$v;}
 function set_status($v){$this->stored_status=$v;}
 function save(){return $this->id;}

 function get_width(){return '';}
 function get_length(){return '';}
}
function add_filter(...$a){$GLOBALS['hooks'][]=$a;}
function add_shortcode(...$a){$GLOBALS['shortcodes'][]=$a;}
class WooCommerce {}
function get_locale(){return 'pl_PL';}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function add_option($k,$v,$deprecated='',$autoload=false){if(array_key_exists($k,$GLOBALS['opts']))return false; $GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);return true;}
function wp_cache_delete(...$a){}
function wp_generate_uuid4(){return uniqid();}
function wc_get_product($id){$GLOBALS['product_reads']=($GLOBALS['product_reads']??0)+1;return $GLOBALS['test_products'][(int)$id]??false;}
function absint($n){return abs((int)$n);}
function wp_parse_id_list($v){return $v===''?[]:array_map('intval',explode(',',$v));}
function wp_parse_args($v,$defaults){return array_merge($defaults,$v);}
function wc_get_attribute_taxonomies(){return [];}
function get_woocommerce_currency(){return 'PLN';}
function wc_tax_enabled(){return $GLOBALS['tax_enabled']??false;}
function wc_prices_include_tax(){return $GLOBALS['prices_include_tax']??false;}
class WC_Tax {
 static function get_base_tax_rates($class){return [23];}
 static function get_shipping_tax_rates($class){return [23];}
 static function calc_inclusive_tax($gross,$rates){return [1=>$gross-$gross/1.23];}
 static function calc_tax($net,$rates,$inclusive=false){return [1=>$net*.23];}
}
function WC(){return (object)['cart'=>$GLOBALS['current_cart']??null,'customer'=>$GLOBALS['customer']??null];}
function wc_get_cart_url(){return 'https://example.com/koszyk/';}
function wp_list_pluck($rows,$key){return array_column($rows,$key);}
function wc_add_notice($message,$type='success'){$GLOBALS['notices'][]=[$message,$type];}
function wc_load_cart(){$GLOBALS['current_cart']=new TestCart();}
function wp_verify_nonce($v,$action){return $v==='nonce';}
function wp_unslash($v){return $v;}
function sanitize_text_field($s){return strip_tags(trim($s));}
function is_admin(){return false;}
function is_singular(...$a){return false;}
function nocache_headers(){}
function get_queried_object_id(){return 0;}
function wp_safe_redirect($url,$status=302,$by='WordPress'){throw new Redirect($url);}
function wp_die($message){throw new RuntimeException($message);}
function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_url($v){return $v;}
function wp_enqueue_script(...$a){}
function wp_enqueue_style(...$a){}
function plugins_url($p,$file){return $p;}
function get_current_blog_id(){return 1;}
function get_permalink($id=null){return 'https://example.com/probki/';}
function wc_print_notices(){}
function wp_nonce_field($a,$n='_wpnonce'){echo '<input type="hidden" name="'.$n.'" value="nonce">';}
class Redirect extends Exception {}
class WC_Shipping_Rate {public $cost,$taxes;function __construct($id,$label,$cost,$taxes,$method){$this->cost=$cost;$this->taxes=$taxes;}}
class FakeDB {
 public $posts='wp_posts',$options='wp_options',$last_error='',$queries=[],$max_batch=0;
 function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
 function get_var($sql){$this->queries[]=$sql;return max(array_keys($GLOBALS['test_products'])?:[0]);}
 function get_col($query){
  $this->queries[]=$query;$args=$query['args'];$ids=array_keys($GLOBALS['test_products']);sort($ids,SORT_NUMERIC);
  $ids=array_values(array_filter($ids,function($id)use($args){return $id>$args[0] && $id<=$args[1] && $GLOBALS['test_products'][$id]->get_status()==='publish';}));
  $this->max_batch=max($this->max_batch,$args[2]);return array_slice($ids,0,$args[2]);
 }
 function query($query){[$key,$value]=$query['args'];if(($GLOBALS['opts'][$key]??null)===$value)unset($GLOBALS['opts'][$key]);return 1;}
}
$GLOBALS['wpdb']=new FakeDB();
class Variable extends Product {
 function get_type(){return 'variable';}function is_type($t){return $t==='variable';}
 function get_children(){throw new Exception('UNBOUNDED CHILD READ');}
}
class Variation extends Product {
 public $parent_id=10;
 function get_type(){return 'variation';}function is_type($t){return $t==='variation';}
 function get_parent_id(){return $this->parent_id;}
}
class TestCart {
 public $items=[],$fail_after=999,$calls=0;
 function get_cart(){return $this->items;}
 function add_to_cart($pid,$qty,$vid,$attrs,$data){
  if(++$this->calls>$this->fail_after)return false;
  $key='line-'.$this->calls;$this->items[$key]=array_merge($data,['product_id'=>$pid,'quantity'=>$qty,'data'=>clone $GLOBALS['test_products'][$pid]]);return $key;
 }
 function remove_cart_item($key){unset($this->items[$key]);}
 function set_quantity($key,$qty,$refresh){$this->items[$key]['quantity']=$qty;}
 function calculate_totals(){}
}
function add_product($id,$class='Product',$attrs=[]){$p=new $class($attrs);$p->id=$id;$GLOBALS['test_products'][$id]=$p;return $p;}
$technical=add_product(99);$technical->meta['_pimex_samples_owned']='yes';
$GLOBALS['opts'][Pimex_Samples_V5::OPTION]=['product'=>99,'page'=>5,'max'=>12,'enabled'=>true,'price'=>'4.00','shipping'=>'14.00'];
function wc_format_decimal($value,$places=2){return number_format((float)$value,$places,'.','');}
function current_user_can($cap){return $GLOBALS['can_manage']??true;}
function check_admin_referer($action){if(!($GLOBALS['nonce_valid']??true))throw new RuntimeException('Invalid nonce');}
function wp_send_json_error($data,$status=400){throw new RuntimeException('JSON error '.$status.': '.$data['message']);}
function wp_date($format,$stamp){return date($format,$stamp);}
function get_edit_post_link($id){return '/wp-admin/post.php?post='.$id;}
function get_post_status($id){return 'publish';}
function get_post_type($id){return 'page';}
function get_preview_post_link($id){return '/preview/';}
function admin_url($path){return '/wp-admin/'.$path;}
function checked($value,$expected=true){if($value==$expected)echo ' checked';}
function disabled($value,$expected=true){if($value==$expected)echo ' disabled';}
function submit_button($text){echo '<button>'.esc_html($text).'</button>';}
function get_post_field($f,$id){return '[pimex_probki]';}
function wp_get_nav_menus(){return [];}
function wp_kses_post($s){return $s;}
function paginate_links($args){return '';}
function add_query_arg($args,$url,$base=null){return $base===null?$url:$base.'?'.$args.'='.$url;}
function wc_get_rounding_precision(){return 6;}
