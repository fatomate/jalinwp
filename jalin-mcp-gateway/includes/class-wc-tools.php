<?php
/**
 * Narrow WooCommerce tools backed by the native wc/v3 REST controllers.
 *
 * Native controllers retain object permissions and HPOS-aware order storage.
 * Every write is registered as mutating and is executed only by the gateway's
 * central reviewed or YOLO execution workflow.
 *
 * @package JalinWP
 */

defined( 'ABSPATH' ) || exit;

final class FG_WC_Tools {

	private const PRODUCT_FIELDS = 'id,name,slug,permalink,type,status,sku,price,regular_price,sale_price,on_sale,manage_stock,stock_quantity,stock_status,categories,images.id,images.src,images.alt,date_modified_gmt';
	private const PRODUCT_DETAIL_FIELDS = self::PRODUCT_FIELDS . ',description,short_description,featured,catalog_visibility,virtual,backorders,attributes,default_attributes,variations';
	private const VARIATION_FIELDS = 'id,description,permalink,status,sku,price,regular_price,sale_price,on_sale,manage_stock,stock_quantity,stock_status,backorders,attributes,image.id,image.src,image.alt,date_modified_gmt';
	private const CATEGORY_FIELDS = 'id,name,slug,parent,description,count';
	private const COUPON_FIELDS = 'id,code,amount,discount_type,description,date_expires,date_expires_gmt,usage_count,individual_use,product_ids,excluded_product_ids,usage_limit,usage_limit_per_user,limit_usage_to_x_items,free_shipping,product_categories,excluded_product_categories,exclude_sale_items,minimum_amount,maximum_amount';
	private const ORDER_FIELDS = 'id,number,status,currency,payment_method,payment_method_title,date_created_gmt,date_modified_gmt,total,total_tax,discount_total,shipping_total,customer_id,date_paid_gmt,date_completed_gmt';
	private const ORDER_DETAIL_FIELDS = self::ORDER_FIELDS . ',billing.first_name,billing.last_name,billing.company,billing.address_1,billing.address_2,billing.city,billing.state,billing.postcode,billing.country,billing.email,billing.phone,shipping.first_name,shipping.last_name,shipping.company,shipping.address_1,shipping.address_2,shipping.city,shipping.state,shipping.postcode,shipping.country,customer_note,line_items.id,line_items.name,line_items.product_id,line_items.variation_id,line_items.quantity,line_items.subtotal,line_items.total,line_items.total_tax,line_items.sku,shipping_lines.id,shipping_lines.method_title,shipping_lines.total,fee_lines.id,fee_lines.name,fee_lines.total,fee_lines.total_tax,coupon_lines.id,coupon_lines.code,coupon_lines.discount';
	private const CUSTOMER_FIELDS = 'id,date_created_gmt,date_modified_gmt,email,first_name,last_name,is_paying_customer';

	/** Register nothing when WooCommerce is not active. */
	public static function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::register_products();
		self::register_variations();
		self::register_categories();
		self::register_coupons();
		self::register_orders();
		self::register_customers();
	}

	private static function register_products(): void {
		$list = FG_Tools::pagination() + array(
			'status'       => self::choice( array( 'any', 'draft', 'pending', 'private', 'publish' ) ),
			'type'         => self::choice( array( 'simple', 'variable', 'grouped', 'external' ) ),
			'sku'          => self::string( 200 ),
			'category'     => FG_Tools::id(),
			'stock_status' => self::choice( array( 'instock', 'outofstock', 'onbackorder' ) ),
			'on_sale'      => array( 'type' => 'boolean' ),
		);
		FG_Tools::register( 'wc_products_list', 'List WooCommerce products with bounded pagination, prices and stock. Prices are decimal strings.', FG_Tools::schema( $list ), static function ( array $args ) {
			return self::request( 'GET', '/products', self::page( $args ), self::PRODUCT_FIELDS );
		}, false, 'edit_products' );
		FG_Tools::register( 'wc_products_get', 'Read one WooCommerce product, including its descriptions, attributes and variation IDs.', FG_Tools::schema( array( 'id' => FG_Tools::id() ), array( 'id' ) ), static function ( array $args ) {
			return self::request( 'GET', '/products/' . $args['id'], array(), self::PRODUCT_DETAIL_FIELDS );
		}, false, 'edit_products' );

		$write = self::product_properties();
		FG_Tools::register( 'wc_products_create', 'Create a new simple or variable product. Defaults to draft. Image IDs must already exist in the media library; no remote image URLs are fetched.', FG_Tools::schema( $write, array( 'name' ) ), static function ( array $args ) {
			$args['status'] = $args['status'] ?? 'draft';
			$args['type']   = $args['type'] ?? 'simple';
			return self::request( 'POST', '/products', self::product_payload( $args ), self::PRODUCT_DETAIL_FIELDS );
		}, true, 'edit_products' );
		FG_Tools::register( 'wc_products_update', 'Update one product. Only supplied fields change; category_ids, image_ids and attributes replace their respective collections.', FG_Tools::schema( array( 'id' => FG_Tools::id() ) + $write, array( 'id' ) ), static function ( array $args ) {
			$id = self::take_id( $args );
			self::require_changes( $args );
			return self::request( 'PUT', '/products/' . $id, self::product_payload( $args ), self::PRODUCT_DETAIL_FIELDS );
		}, true, 'edit_products' );
		FG_Tools::register( 'wc_products_trash', 'Move one product to the trash. Does not offer permanent deletion.', FG_Tools::schema( array( 'id' => FG_Tools::id() ), array( 'id' ) ), static function ( array $args ) {
			self::require_trash();
			return self::request( 'DELETE', '/products/' . $args['id'], array( 'force' => false ), 'id,name,status' );
		}, true, 'delete_products' );
	}

	private static function register_variations(): void {
		FG_Tools::register( 'wc_variations_list', 'List variations of a variable WooCommerce product with prices, stock and attribute options.', FG_Tools::schema( array( 'product_id' => FG_Tools::id() ) + FG_Tools::pagination(), array( 'product_id' ) ), static function ( array $args ) {
			$product_id = $args['product_id'];
			unset( $args['product_id'] );
			return self::request( 'GET', '/products/' . $product_id . '/variations', self::page( $args ), self::VARIATION_FIELDS );
		}, false, 'edit_products' );

		$write = self::variation_properties();
		FG_Tools::register( 'wc_variations_create', 'Create a variation for an existing variable product. Defaults to private until explicitly published. The parent must already define matching variation attributes.', FG_Tools::schema( array( 'product_id' => FG_Tools::id() ) + $write, array( 'product_id', 'attributes' ) ), static function ( array $args ) {
			$product_id = $args['product_id'];
			unset( $args['product_id'] );
			$args['status'] = $args['status'] ?? 'private';
			return self::request( 'POST', '/products/' . $product_id . '/variations', self::variation_payload( $args ), self::VARIATION_FIELDS );
		}, true, 'edit_products' );
		FG_Tools::register( 'wc_variations_update', 'Change price, stock, status, description, image or attributes of one product variation.', FG_Tools::schema( array( 'product_id' => FG_Tools::id(), 'id' => FG_Tools::id() ) + $write, array( 'product_id', 'id' ) ), static function ( array $args ) {
			$product_id = $args['product_id'];
			unset( $args['product_id'] );
			$id = self::take_id( $args );
			self::require_changes( $args );
			return self::request( 'PUT', '/products/' . $product_id . '/variations/' . $id, self::variation_payload( $args ), self::VARIATION_FIELDS );
		}, true, 'edit_products' );
	}

	private static function register_categories(): void {
		FG_Tools::register( 'wc_product_categories_list', 'List WooCommerce product categories, including empty categories.', FG_Tools::schema( FG_Tools::pagination() + array( 'parent' => array( 'type' => 'integer', 'minimum' => 0 ) ) ), static function ( array $args ) {
			$args['hide_empty'] = false;
			return self::request( 'GET', '/products/categories', self::page( $args ), self::CATEGORY_FIELDS );
		}, false, 'manage_product_terms' );
		$write = array(
			'name'        => self::string( 200, 1 ),
			'slug'        => self::string( 200, 1 ),
			'parent'      => array( 'type' => 'integer', 'minimum' => 0 ),
			'description' => self::string( 10000 ),
		);
		FG_Tools::register( 'wc_product_categories_create', 'Create a WooCommerce product category with an optional parent category.', FG_Tools::schema( $write, array( 'name' ) ), static function ( array $args ) {
			return self::request( 'POST', '/products/categories', self::clean_text( $args ), self::CATEGORY_FIELDS );
		}, true, 'manage_product_terms' );
		FG_Tools::register( 'wc_product_categories_update', 'Update a WooCommerce product category.', FG_Tools::schema( array( 'id' => FG_Tools::id() ) + $write, array( 'id' ) ), static function ( array $args ) {
			$id = self::take_id( $args );
			self::require_changes( $args );
			return self::request( 'PUT', '/products/categories/' . $id, self::clean_text( $args ), self::CATEGORY_FIELDS );
		}, true, 'manage_product_terms' );
	}

	private static function register_coupons(): void {
		FG_Tools::register( 'wc_coupons_list', 'List coupon configuration and usage counts. Customer email restrictions, redemption identities and metadata are omitted.', FG_Tools::schema( FG_Tools::pagination() + array( 'code' => self::string( 200 ) ) ), static function ( array $args ) {
			return self::request( 'GET', '/coupons', self::page( $args ), self::COUPON_FIELDS );
		}, false, 'manage_woocommerce' );
		$write = array(
			'code'                        => self::string( 200, 1 ),
			'discount_type'               => self::choice( array( 'percent', 'fixed_cart', 'fixed_product' ) ),
			'amount'                      => self::decimal(),
			'description'                 => self::string( 4000 ),
			'date_expires'                => self::date_time(),
			'individual_use'              => array( 'type' => 'boolean' ),
			'product_ids'                 => self::ids(),
			'excluded_product_ids'        => self::ids(),
			'usage_limit'                 => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000000 ),
			'usage_limit_per_user'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000000 ),
			'limit_usage_to_x_items'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000000 ),
			'free_shipping'               => array( 'type' => 'boolean' ),
			'product_categories'          => self::ids(),
			'excluded_product_categories' => self::ids(),
			'exclude_sale_items'          => array( 'type' => 'boolean' ),
			'minimum_amount'              => self::decimal(),
			'maximum_amount'              => self::decimal(),
		);
		FG_Tools::register( 'wc_coupons_create', 'Create a coupon that affects checkout discounts. Amounts must be decimal strings. Supply expiry and usage limits when needed.', FG_Tools::schema( $write, array( 'code', 'discount_type', 'amount' ) ), static function ( array $args ) {
			self::validate_coupon( $args );
			return self::request( 'POST', '/coupons', self::clean_text( $args ), self::COUPON_FIELDS );
		}, true, 'manage_woocommerce' );
		FG_Tools::register( 'wc_coupons_update', 'Update one coupon. Discount changes affect subsequent checkout use.', FG_Tools::schema( array( 'id' => FG_Tools::id() ) + $write, array( 'id' ) ), static function ( array $args ) {
			$id = self::take_id( $args );
			self::require_changes( $args );
			self::validate_coupon( $args );
			return self::request( 'PUT', '/coupons/' . $id, self::clean_text( $args ), self::COUPON_FIELDS );
		}, true, 'manage_woocommerce' );
		FG_Tools::register( 'wc_coupons_trash', 'Move one coupon to the trash, preventing future redemption. No permanent deletion.', FG_Tools::schema( array( 'id' => FG_Tools::id() ), array( 'id' ) ), static function ( array $args ) {
			self::require_trash();
			return self::request( 'DELETE', '/coupons/' . $args['id'], array( 'force' => false ), 'id,code' );
		}, true, 'manage_woocommerce' );
	}

	private static function register_orders(): void {
		$statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed' );
		$list     = FG_Tools::pagination() + array(
			'status'   => self::choice( array_merge( array( 'any', 'refunded' ), $statuses ) ),
			'customer' => FG_Tools::id(),
			'after'    => self::date_time(),
			'before'   => self::date_time(),
		);
		FG_Tools::register( 'wc_orders_list', 'Read order summaries using WooCommerce native order storage. Excludes addresses, payment credentials, transaction IDs, order keys and custom metadata.', FG_Tools::schema( $list ), static function ( array $args ) {
			return self::request( 'GET', '/orders', self::page( $args ), self::ORDER_FIELDS );
		}, false, 'manage_woocommerce', true );
		FG_Tools::register( 'wc_orders_get', 'Read one order, billing and shipping contact details, product line items, fees and coupons. Contains customer personal data. Payment credentials, transaction IDs, order keys and custom metadata are excluded.', FG_Tools::schema( array( 'id' => FG_Tools::id() ), array( 'id' ) ), static function ( array $args ) {
			return self::request( 'GET', '/orders/' . $args['id'], array(), self::ORDER_DETAIL_FIELDS );
		}, false, 'manage_woocommerce', true );

		$common = array(
			'billing'       => self::address( true ),
			'shipping'      => self::address( false ),
			'customer_note' => self::string( 4000 ),
			'coupon_lines'  => array( 'type' => 'array', 'maxItems' => 20, 'items' => FG_Tools::schema( array( 'code' => self::string( 200, 1 ) ), array( 'code' ) ) ),
		);
		$create = $common + array(
			'customer_id' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Registered customer ID, or 0 for a guest order.' ),
			'line_items' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => FG_Tools::schema( array(
				'product_id'   => FG_Tools::id(),
				'variation_id' => FG_Tools::id(),
				'quantity'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000 ),
			), array( 'product_id', 'quantity' ) ) ),
			'fee_lines' => array( 'type' => 'array', 'maxItems' => 20, 'items' => self::fee( false ) ),
			'shipping_lines' => array( 'type' => 'array', 'maxItems' => 10, 'items' => FG_Tools::schema( array(
				'method_id'    => self::string( 100, 1 ),
				'method_title' => self::string( 200, 1 ),
				'total'        => self::decimal(),
			), array( 'method_id', 'method_title', 'total' ) ) ),
		);
		FG_Tools::register( 'wc_orders_create', 'Create a manual unpaid order, always pending payment. Uses catalog product prices and store currency; optional manual fees, shipping charges and coupons change totals. Native WooCommerce or extension hooks may send notifications. Does not collect payment.', FG_Tools::schema( $create, array( 'line_items' ) ), static function ( array $args ) {
			$args = self::order_payload( $args );
			$args['status'] = 'pending';
			return self::request( 'POST', '/orders', $args, self::ORDER_DETAIL_FIELDS );
		}, true, 'manage_woocommerce', true );

		$update = $common + array(
			'id' => FG_Tools::id(),
			'line_items' => array( 'type' => 'array', 'maxItems' => 50, 'items' => FG_Tools::schema( array(
				'id'       => FG_Tools::id(),
				'quantity' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'New quantity for an existing order line; zero removes the line.' ),
			), array( 'id', 'quantity' ) ) ),
			'fee_lines' => array( 'type' => 'array', 'maxItems' => 20, 'items' => self::fee( true ) ),
		);
		FG_Tools::register( 'wc_orders_update', 'Update order addresses, customer note, product quantities, manual fees or coupons. Address and financial edits require unpaid pending/on-hold orders. Quantity changes scale existing line prices proportionally at store currency precision; zero removes a line. Omitted fee IDs create fees. coupon_lines REPLACES all applied coupons (empty array removes all); existing coupons otherwise recalculate. Native totals, stock and notification hooks may run. No money is charged or refunded.', FG_Tools::schema( $update, array( 'id' ) ), static function ( array $args ) {
			$id = self::take_id( $args );
			self::require_changes( $args );
			self::validate_order_edit( $id, $args );
			return self::request( 'PUT', '/orders/' . $id, self::order_payload( $args ), self::ORDER_DETAIL_FIELDS );
		}, true, 'manage_woocommerce', true );

		FG_Tools::register( 'wc_orders_update_status', 'Change an order status. Execution can trigger stock changes, customer emails and extension hooks. This does not take payments or issue refunds; refunded and trash statuses are unavailable.', FG_Tools::schema( array( 'id' => FG_Tools::id(), 'status' => self::choice( $statuses ) ), array( 'id', 'status' ) ), static function ( array $args ) {
			return self::request( 'PUT', '/orders/' . $args['id'], array( 'status' => $args['status'] ), self::ORDER_FIELDS );
		}, true, 'manage_woocommerce', true );
		FG_Tools::register( 'wc_orders_add_note', 'Add one internal order note. The note is not sent to the customer by WooCommerce.', FG_Tools::schema( array( 'id' => FG_Tools::id(), 'note' => self::string( 4000, 1 ) ), array( 'id', 'note' ) ), static function ( array $args ) {
			return self::request( 'POST', '/orders/' . $args['id'] . '/notes', array( 'note' => sanitize_textarea_field( $args['note'] ), 'customer_note' => false ), 'id,date_created_gmt,note,customer_note' );
		}, true, 'manage_woocommerce', true );
	}

	private static function register_customers(): void {
		FG_Tools::register( 'wc_customers_list', 'Read registered customers, including names and email addresses. Customer access must be enabled separately. Addresses, usernames and metadata are excluded.', FG_Tools::schema( FG_Tools::pagination() + array( 'email' => self::string( 254, 3 ) ) ), static function ( array $args ) {
			$args['role'] = 'customer';
			return self::request( 'GET', '/customers', self::page( $args ), self::CUSTOMER_FIELDS );
		}, false, 'manage_woocommerce', true );
		FG_Tools::register( 'wc_customers_get', 'Read a customer profile by ID, including name and email. Addresses, usernames and metadata are excluded. Guest checkouts do not have customer profiles.', FG_Tools::schema( array( 'id' => FG_Tools::id() ), array( 'id' ) ), static function ( array $args ) {
			return self::request( 'GET', '/customers/' . $args['id'], array(), self::CUSTOMER_FIELDS );
		}, false, 'manage_woocommerce', true );
	}

	private static function address( bool $billing ): array {
		$properties = array();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $field ) {
			$properties[ $field ] = self::string( 200 );
		}
		$properties['country'] = array( 'type' => 'string', 'pattern' => '^[A-Z]{2}$', 'description' => 'ISO 3166-1 alpha-2 country code, e.g. MY.' );
		if ( $billing ) {
			$properties['email'] = self::string( 254 ) + array( 'format' => 'email' );
			$properties['phone'] = self::string( 50 );
		}
		return FG_Tools::schema( $properties );
	}

	private static function fee( bool $updating ): array {
		$properties = array(
			'name'       => self::string( 200, 1 ),
			'total'      => self::decimal(),
			'tax_status' => self::choice( array( 'taxable', 'none' ) ),
			'tax_class'  => self::string( 100 ),
		);
		if ( $updating ) {
			$properties['id'] = FG_Tools::id();
		}
		return FG_Tools::schema( $properties, array( 'name', 'total' ) );
	}

	private static function order_payload( array $args ): array {
		if ( isset( $args['customer_note'] ) ) {
			$args['customer_note'] = sanitize_textarea_field( $args['customer_note'] );
		}
		foreach ( array( 'billing', 'shipping' ) as $address ) {
			if ( isset( $args[ $address ] ) ) {
				$args[ $address ] = array_map( 'sanitize_text_field', $args[ $address ] );
			}
		}
		return $args;
	}

	/** Verify IDs belong to the order before forwarding a partial native update. */
	private static function validate_order_edit( int $id, array &$args ): void {
		$financial = array_intersect_key( $args, array_flip( array( 'billing', 'shipping', 'line_items', 'fee_lines', 'coupon_lines' ) ) );
		if ( ! $financial ) {
			return;
		}
		$response = self::request( 'GET', '/orders/' . $id, array(), 'id,status,date_paid_gmt,line_items.id,line_items.quantity,line_items.subtotal,line_items.total,fee_lines.id,coupon_lines.code' );
		$order = $response['data'] ?? null;
		if ( ! is_array( $order ) || ! isset( $order['id'], $order['status'] ) ) {
			throw new RuntimeException( 'Could not verify the order before applying financial changes.' );
		}
		if ( ! in_array( $order['status'], array( 'pending', 'on-hold' ), true ) || ! empty( $order['date_paid_gmt'] ) ) {
			throw new RuntimeException( 'Address and financial edits are available only for unpaid pending or on-hold orders because WooCommerce recalculates totals.' );
		}
		foreach ( array( 'line_items', 'fee_lines' ) as $collection ) {
			$existing_ids = array_map( 'intval', array_column( $order[ $collection ] ?? array(), 'id' ) );
			$seen = array();
			foreach ( $args[ $collection ] ?? array() as $item ) {
				if ( isset( $item['id'] ) ) {
					if ( ! in_array( $item['id'], $existing_ids, true ) || isset( $seen[ $item['id'] ] ) ) {
						throw new InvalidArgumentException( 'Each supplied line ID must belong to this order and appear only once.' );
					}
					$seen[ $item['id'] ] = true;
				}
			}
		}
		$original_lines = array_column( $order['line_items'] ?? array(), null, 'id' );
		foreach ( $args['line_items'] ?? array() as $index => $item ) {
			if ( $item['quantity'] > 0 ) {
				$original = $original_lines[ $item['id'] ];
				$old_quantity = $original['quantity'] ?? 0;
				if ( ! is_int( $old_quantity ) || $old_quantity < 1 || $old_quantity > 100000000 ) {
					throw new RuntimeException( 'This line has an unsupported original quantity; edit it in WooCommerce.' );
				}
				foreach ( array( 'subtotal', 'total' ) as $amount ) {
					$args['line_items'][ $index ][ $amount ] = self::proportional_amount( (string) $original[ $amount ], $old_quantity, $item['quantity'] );
				}
			}
		}
		// The native coupon operation replaces the complete coupon set. Supplying
		// the existing codes also makes WooCommerce recalculate coupon discounts
		// when a quantity, address or manual fee changes.
		if ( ! array_key_exists( 'coupon_lines', $args ) && ! empty( $order['coupon_lines'] ) ) {
			if ( count( $order['coupon_lines'] ) > 20 ) {
				throw new RuntimeException( 'This order has more than 20 coupons; edit it in WooCommerce.' );
			}
			$args['coupon_lines'] = $order['coupon_lines'];
		}
	}

	/** Scale without binary floating point, preserving existing negotiated prices. */
	private static function proportional_amount( string $amount, int $old_quantity, int $new_quantity ): string {
		$precision = wc_get_price_decimals();
		if ( $precision < 0 || $precision > 6 || ! preg_match( '/^([0-9]+)(?:\.([0-9]+))?$/', $amount, $match ) || strlen( $match[2] ?? '' ) > $precision ) {
			throw new RuntimeException( 'The original line amount has unsupported currency precision; edit it in WooCommerce.' );
		}
		$digits = ltrim( $match[1] . str_pad( $match[2] ?? '', $precision, '0' ), '0' );
		if ( strlen( $digits ) > 15 ) {
			throw new RuntimeException( 'The original line amount is too large for a safe proportional update.' );
		}
		$minor = (int) $digits;
		$whole = intdiv( $minor, $old_quantity );
		$remainder = $minor % $old_quantity;
		$rounded_remainder = intdiv( $remainder * $new_quantity + intdiv( $old_quantity, 2 ), $old_quantity );
		if ( $whole > intdiv( PHP_INT_MAX - $rounded_remainder, $new_quantity ) ) {
			throw new RuntimeException( 'The new line amount is too large for a safe proportional update.' );
		}
		$scaled = (string) ( $whole * $new_quantity + $rounded_remainder );
		if ( 0 === $precision ) {
			return $scaled;
		}
		$scaled = str_pad( $scaled, $precision + 1, '0', STR_PAD_LEFT );
		return substr( $scaled, 0, -$precision ) . '.' . substr( $scaled, -$precision );
	}

	private static function product_properties(): array {
		return array(
			'name'              => self::string( 200, 1 ),
			'type'              => self::choice( array( 'simple', 'variable' ) ),
			'status'            => self::choice( array( 'draft', 'pending', 'private', 'publish' ) ),
			'slug'              => self::string( 200, 1 ),
			'description'       => self::string( 50000 ),
			'short_description' => self::string( 10000 ),
			'sku'               => self::string( 100 ),
			'regular_price'     => self::decimal(),
			'sale_price'        => self::decimal( true ),
			'featured'          => array( 'type' => 'boolean' ),
			'virtual'           => array( 'type' => 'boolean' ),
			'catalog_visibility'=> self::choice( array( 'visible', 'catalog', 'search', 'hidden' ) ),
			'manage_stock'      => array( 'type' => 'boolean' ),
			'stock_quantity'    => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000000 ),
			'stock_status'      => self::choice( array( 'instock', 'outofstock', 'onbackorder' ) ),
			'backorders'        => self::choice( array( 'no', 'notify', 'yes' ) ),
			'category_ids'      => self::ids(),
			'image_ids'         => self::ids( 20 ),
			'attributes'        => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => FG_Tools::schema( array(
					'id'        => FG_Tools::id(),
					'name'      => self::string( 200, 1 ),
					'visible'   => array( 'type' => 'boolean' ),
					'variation' => array( 'type' => 'boolean' ),
					'options'   => array( 'type' => 'array', 'maxItems' => 50, 'items' => self::string( 200, 1 ) ),
				), array( 'name', 'options' ) ),
			),
		);
	}

	private static function variation_properties(): array {
		$properties = array_intersect_key( self::product_properties(), array_flip( array( 'status', 'description', 'sku', 'regular_price', 'sale_price', 'virtual', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ) ) );
		$properties['status'] = self::choice( array( 'private', 'publish' ) );
		$properties['image_id'] = FG_Tools::id();
		$properties['attributes'] = array(
			'type'     => 'array',
			'maxItems' => 20,
			'minItems' => 1,
			'items'    => FG_Tools::schema( array(
				'id'     => FG_Tools::id(),
				'name'   => self::string( 200, 1 ),
				'option' => self::string( 200, 1 ),
			), array( 'name', 'option' ) ),
		);
		return $properties;
	}

	private static function product_payload( array $args ): array {
		foreach ( array( 'category_ids' => 'categories', 'image_ids' => 'images' ) as $source => $target ) {
			if ( array_key_exists( $source, $args ) ) {
				$args[ $target ] = array_map( static fn( $id ) => array( 'id' => $id ), $args[ $source ] );
				unset( $args[ $source ] );
			}
		}
		return self::clean_text( $args );
	}

	private static function variation_payload( array $args ): array {
		if ( isset( $args['image_id'] ) ) {
			$args['image'] = array( 'id' => $args['image_id'] );
			unset( $args['image_id'] );
		}
		return self::clean_text( $args );
	}

	/** Always request a fixed field allowlist, including for mutation responses. */
	private static function request( string $method, string $path, array $args, string $fields ) {
		// Some WooCommerce controllers only materialize collections when their
		// top-level name is present. Request those roots, then apply the exact
		// nested projection locally before anything leaves this handler.
		$roots = array_map( static fn( $field ) => explode( '.', $field, 2 )[0], explode( ',', $fields ) );
		$args['_fields'] = implode( ',', array_unique( $roots ) );
		$result = FG_Tools::rest( $method, '/wc/v3' . $path, $args );
		$selection = array();
		foreach ( explode( ',', $fields ) as $field ) {
			$parts = explode( '.', $field );
			$cursor =& $selection;
			foreach ( $parts as $part ) {
				if ( ! isset( $cursor[ $part ] ) ) {
					$cursor[ $part ] = array();
				}
				$cursor =& $cursor[ $part ];
			}
			$cursor = true;
			unset( $cursor );
		}
		$result['data'] = self::project( $result['data'], $selection );
		return $result;
	}

	/** WordPress' nested field filter does not recurse into nested item lists. */
	private static function project( array $data, array $selection ): array {
		if ( array_is_list( $data ) ) {
			return array_map( static fn( $item ) => is_array( $item ) ? self::project( $item, $selection ) : array(), $data );
		}
		$result = array();
		foreach ( $selection as $key => $children ) {
			if ( array_key_exists( $key, $data ) ) {
				$result[ $key ] = true === $children ? $data[ $key ] : ( is_array( $data[ $key ] ) ? self::project( $data[ $key ], $children ) : array() );
			}
		}
		return $result;
	}

	private static function page( array $args ): array {
		$args['page']     = $args['page'] ?? 1;
		$args['per_page'] = $args['per_page'] ?? 10;
		return $args;
	}

	private static function clean_text( array $args ): array {
		foreach ( array( 'description', 'short_description' ) as $field ) {
			if ( isset( $args[ $field ] ) ) {
				$args[ $field ] = wp_kses_post( $args[ $field ] );
			}
		}
		foreach ( array( 'name', 'sku', 'code' ) as $field ) {
			if ( isset( $args[ $field ] ) ) {
				$args[ $field ] = sanitize_text_field( $args[ $field ] );
			}
		}
		if ( isset( $args['slug'] ) ) {
			$args['slug'] = sanitize_title( $args['slug'] );
		}
		return $args;
	}

	private static function take_id( array &$args ): int {
		$id = $args['id'];
		unset( $args['id'] );
		return $id;
	}

	private static function require_changes( array $args ): void {
		if ( ! $args ) {
			throw new InvalidArgumentException( 'Supply at least one field to update.' );
		}
	}

	private static function require_trash(): void {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			throw new RuntimeException( 'Trash is disabled on this site; this tool will not permanently delete the item.' );
		}
	}

	private static function validate_coupon( array $args ): void {
		if ( isset( $args['discount_type'], $args['amount'] ) && 'percent' === $args['discount_type'] && (float) $args['amount'] > 100 ) {
			throw new InvalidArgumentException( 'A percentage coupon cannot exceed 100.' );
		}
	}

	private static function string( int $maximum, int $minimum = 0 ): array {
		return array( 'type' => 'string', 'minLength' => $minimum, 'maxLength' => $maximum );
	}

	private static function choice( array $values ): array {
		return array( 'type' => 'string', 'enum' => $values );
	}

	private static function decimal( bool $allow_empty = false ): array {
		return array(
			'type'        => 'string',
			'pattern'     => $allow_empty ? '^(?:[0-9]+(?:\\.[0-9]{1,6})?)?$' : '^[0-9]+(?:\\.[0-9]{1,6})?$',
			'maxLength'   => 24,
			'description' => $allow_empty ? 'Non-negative decimal string, e.g. "19.90"; empty string clears the sale price.' : 'Non-negative decimal string, e.g. "19.90". Do not send a JSON number.',
		);
	}

	private static function ids( int $maximum = 50 ): array {
		return array( 'type' => 'array', 'maxItems' => $maximum, 'uniqueItems' => true, 'items' => FG_Tools::id() );
	}

	private static function date_time(): array {
		return array(
			'type'        => 'string',
			'pattern'     => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})?$',
			'maxLength'   => 25,
			'description' => 'ISO 8601 date-time, e.g. 2026-09-01T00:00:00. An omitted offset uses the store timezone.',
		);
	}
}
