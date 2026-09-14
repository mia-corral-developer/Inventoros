<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LabDemoSeeder — datos de demostración para ENTENDER el sistema.
 *
 * Siembra 2 casos de ejemplo por cada flujo (categorías, ubicaciones, bodegas,
 * proveedores, clientes, productos —con variantes/lotes/seriales—, kits y
 * ensambles, órdenes de venta, devoluciones, órdenes de compra, transferencias
 * de stock, auditorías de stock, órdenes de trabajo, reportes, webhooks, roles).
 *
 * Idempotente: usa updateOrCreate por clave natural, se puede re-ejecutar.
 * Todo apunta a la organización "Whitelabel" y al usuario admin existente.
 *
 * Ejecutar:  php artisan db:seed --class=LabDemoSeeder --force
 */
class LabDemoSeeder extends Seeder
{
    private int $orgId;
    private int $userId;

    /** @var array<string,int> */
    private array $cat = [];
    /** @var array<string,int> */
    private array $loc = [];
    /** @var array<string,int> */
    private array $wh = [];
    /** @var array<string,int> */
    private array $sup = [];
    /** @var array<string,int> */
    private array $cus = [];
    /** @var array<string,int> */
    private array $prod = [];
    /** @var array<string,int> */
    private array $order = [];

    public function run(): void
    {
        $org = DB::table('organizations')->where('name', 'Whitelabel')->first()
            ?? DB::table('organizations')->orderBy('id')->first();
        if (! $org) {
            $this->command->error('No hay organización. Abortando.');
            return;
        }
        $this->orgId = (int) $org->id;

        $admin = DB::table('users')->where('organization_id', $this->orgId)
            ->orderByRaw("CASE WHEN role='admin' THEN 0 ELSE 1 END")->orderBy('id')->first();
        if (! $admin) {
            $this->command->error('No hay usuario admin. Abortando.');
            return;
        }
        $this->userId = (int) $admin->id;

        $this->rolesAndUsers();
        $this->categories();
        $this->warehousesAndLocations();
        $this->suppliers();
        $this->customers();
        $this->products();
        $this->variantsBatchesSerials();
        $this->kitsAndAssemblies();
        $this->orders();
        $this->returns();
        $this->purchaseOrders();
        $this->stockTransfers();
        $this->stockAudits();
        $this->workOrders();
        $this->reports();
        $this->webhooks();

        $this->command->info("✅ LabDemoSeeder listo para la organización #{$this->orgId}.");
    }

    private function ts(): string { return now()->toDateTimeString(); }

    private function upsert(string $table, array $keys, array $values): int
    {
        $row = DB::table($table)->where($keys)->first();
        if ($row) {
            DB::table($table)->where('id', $row->id)->update($values + ['updated_at' => $this->ts()]);
            return (int) $row->id;
        }
        return (int) DB::table($table)->insertGetId($keys + $values + [
            'created_at' => $this->ts(), 'updated_at' => $this->ts(),
        ]);
    }

    // ─────────────────────────── Roles & Users ───────────────────────────
    private function rolesAndUsers(): void
    {
        $this->role('Administrador', 'admin', 'Acceso total al sistema.', [
            'view_products','manage_products','view_orders','manage_orders',
            'manage_users','manage_roles','view_reports','manage_import_export',
        ]);
        $this->role('Operador de Bodega', 'operator', 'Gestiona inventario y pedidos, sin acceso a configuración.', [
            'view_products','manage_products','view_orders','manage_orders',
            'view_stock_transfers','view_stock_audits',
        ]);

        // Usuario operador de ejemplo (segundo caso del flujo Usuarios).
        $this->upsert('users', ['email' => 'operador@whitelabel.lat'], [
            'name' => 'Camila Restrepo',
            'password' => '$2y$12$e0MYzXyjpJS7Pd0RVvHwHe1HlCS4bZJ18JuywdBZ0Eon6v2n4uSBa', // password
            'organization_id' => $this->orgId,
            'role' => 'manager',
            'email_verified_at' => $this->ts(),
            'notification_preferences' => json_encode(['email_notifications' => true, 'low_stock_alerts' => true]),
        ]);
    }

    private function role(string $name, string $slug, string $desc, array $perms): void
    {
        $this->upsert('roles', ['organization_id' => $this->orgId, 'slug' => $slug], [
            'name' => $name, 'description' => $desc,
            'permissions' => json_encode($perms), 'is_system' => false,
        ]);
    }

    // ─────────────────────────── Catálogo base ───────────────────────────
    private function categories(): void
    {
        foreach ([
            ['Tecnología', 'Equipos de cómputo, periféricos y accesorios electrónicos.'],
            ['Papelería y Oficina', 'Insumos de oficina, papel y consumibles.'],
        ] as [$name, $desc]) {
            $this->cat[$name] = $this->upsert('product_categories',
                ['organization_id' => $this->orgId, 'slug' => Str::slug($name)],
                ['name' => $name, 'description' => $desc, 'is_active' => true]);
        }
    }

    private function warehousesAndLocations(): void
    {
        $this->wh['central'] = $this->upsert('warehouses',
            ['organization_id' => $this->orgId, 'code' => 'WH-CENTRAL'], [
            'name' => 'Bodega Central', 'description' => 'Centro de distribución principal (Bogotá).',
            'address_line_1' => 'Calle 80 # 45-12', 'city' => 'Bogotá', 'province' => 'Cundinamarca',
            'postal_code' => '111321', 'country' => 'CO', 'phone' => '+57 601 555 0100',
            'email' => 'central@whitelabel.lat', 'manager_name' => 'Andrés Corral',
            'timezone' => 'America/Bogota', 'currency' => 'COP', 'is_default' => true,
            'is_active' => true, 'priority' => 10]);

        $this->wh['norte'] = $this->upsert('warehouses',
            ['organization_id' => $this->orgId, 'code' => 'WH-NORTE'], [
            'name' => 'Bodega Norte', 'description' => 'Centro de fulfillment regional (Medellín).',
            'address_line_1' => 'Carrera 48 # 20-30', 'city' => 'Medellín', 'province' => 'Antioquia',
            'postal_code' => '050021', 'country' => 'CO', 'phone' => '+57 604 555 0200',
            'email' => 'norte@whitelabel.lat', 'manager_name' => 'Camila Restrepo',
            'timezone' => 'America/Bogota', 'currency' => 'COP', 'is_default' => false,
            'is_active' => true, 'priority' => 5]);

        $this->loc['central'] = $this->upsert('product_locations',
            ['organization_id' => $this->orgId, 'code' => 'CENTRAL-A1'], [
            'name' => 'Pasillo A - Estante 1', 'description' => 'Zona de alta rotación.',
            'aisle' => 'A', 'shelf' => '1', 'bin' => 'A1-01',
            'is_active' => true, 'warehouse_id' => $this->wh['central']]);

        $this->loc['norte'] = $this->upsert('product_locations',
            ['organization_id' => $this->orgId, 'code' => 'NORTE-B1'], [
            'name' => 'Pasillo B - Estante 1', 'description' => 'Zona de consumibles.',
            'aisle' => 'B', 'shelf' => '1', 'bin' => 'B1-01',
            'is_active' => true, 'warehouse_id' => $this->wh['norte']]);
    }

    private function suppliers(): void
    {
        $this->sup['tecno'] = $this->upsert('suppliers',
            ['organization_id' => $this->orgId, 'code' => 'SUP-001'], [
            'name' => 'TecnoAndina S.A.S.', 'contact_name' => 'Laura Gómez',
            'email' => 'ventas@tecnoandina.co', 'phone' => '+57 601 700 1234',
            'address' => 'Av. El Dorado # 68-50', 'city' => 'Bogotá', 'state' => 'Cundinamarca',
            'zip_code' => '111321', 'country' => 'Colombia', 'website' => 'https://tecnoandina.co',
            'payment_terms' => '30 días', 'currency' => 'COP', 'is_active' => true]);

        $this->sup['papel'] = $this->upsert('suppliers',
            ['organization_id' => $this->orgId, 'code' => 'SUP-002'], [
            'name' => 'Papelera del Sur Ltda.', 'contact_name' => 'Jorge Peña',
            'email' => 'pedidos@papeleradelsur.co', 'phone' => '+57 604 555 8899',
            'address' => 'Calle 30 # 55-40', 'city' => 'Medellín', 'state' => 'Antioquia',
            'zip_code' => '050021', 'country' => 'Colombia', 'payment_terms' => '15 días',
            'currency' => 'COP', 'is_active' => true]);
    }

    private function customers(): void
    {
        $this->cus['condor'] = $this->upsert('customers',
            ['organization_id' => $this->orgId, 'code' => 'CLI-001'], [
            'name' => 'Distribuidora El Cóndor S.A.S.', 'company_name' => 'Distribuidora El Cóndor S.A.S.',
            'contact_name' => 'María Rodríguez', 'email' => 'compras@elcondor.co',
            'phone' => '+57 601 400 7788', 'billing_address' => 'Carrera 7 # 32-16',
            'billing_city' => 'Bogotá', 'billing_state' => 'Cundinamarca', 'billing_zip_code' => '110311',
            'billing_country' => 'Colombia', 'tax_id' => '900.123.456-7',
            'payment_terms' => '30 días', 'credit_limit' => 50000000, 'currency' => 'COP',
            'is_active' => true]);

        $this->cus['andes'] = $this->upsert('customers',
            ['organization_id' => $this->orgId, 'code' => 'CLI-002'], [
            'name' => 'Constructora Los Andes Ltda.', 'company_name' => 'Constructora Los Andes Ltda.',
            'contact_name' => 'Carlos Herrera', 'email' => 'pagos@losandes.co',
            'phone' => '+57 604 200 3344', 'billing_address' => 'Transversal 39 # 71-20',
            'billing_city' => 'Medellín', 'billing_state' => 'Antioquia', 'billing_zip_code' => '050031',
            'billing_country' => 'Colombia', 'tax_id' => '890.987.654-3',
            'payment_terms' => '45 días', 'credit_limit' => 80000000, 'currency' => 'COP',
            'is_active' => true]);
    }

    // ─────────────────────────── Productos ───────────────────────────
    private function products(): void
    {
        // Caso 1 — producto con seguimiento por SERIE
        $this->prod['laptop'] = $this->upsert('products',
            ['organization_id' => $this->orgId, 'sku' => 'PROD-001'], [
            'type' => 'standard', 'name' => 'Laptop Lenovo ThinkPad E14',
            'description' => 'Portátil empresarial 14", Core i5, 16GB RAM, 512GB SSD.',
            'price' => 3299000, 'purchase_price' => 2650000, 'currency' => 'COP',
            'stock' => 15, 'min_stock' => 3, 'max_stock' => 50,
            'reorder_point' => 5, 'reorder_quantity' => 10,
            'category_id' => $this->cat['Tecnología'], 'location_id' => $this->loc['central'],
            'is_active' => true, 'has_variants' => false, 'tracking_type' => 'serial']);

        // Caso 2 — producto con seguimiento por LOTE
        $this->prod['papel'] = $this->upsert('products',
            ['organization_id' => $this->orgId, 'sku' => 'PROD-002'], [
            'type' => 'standard', 'name' => 'Resma Papel A4 500 hojas',
            'description' => 'Resma de papel bond blanco 75g, 500 hojas.',
            'price' => 18900, 'purchase_price' => 11500, 'currency' => 'COP',
            'stock' => 240, 'min_stock' => 40, 'max_stock' => 1000,
            'reorder_point' => 80, 'reorder_quantity' => 200,
            'category_id' => $this->cat['Papelería y Oficina'], 'location_id' => $this->loc['norte'],
            'is_active' => true, 'has_variants' => false, 'tracking_type' => 'batch']);

        // Caso 3 — producto CON VARIANTES (talla/color)
        $this->prod['polo'] = $this->upsert('products',
            ['organization_id' => $this->orgId, 'sku' => 'PROD-003'], [
            'type' => 'standard', 'name' => 'Camiseta Polo Corporativa',
            'description' => 'Polo corporativa con logo bordado. Disponible en varias tallas y colores.',
            'price' => 65000, 'purchase_price' => 32000, 'currency' => 'COP',
            'stock' => 0, 'min_stock' => 10, 'max_stock' => 500,
            'category_id' => $this->cat['Papelería y Oficina'], 'location_id' => $this->loc['norte'],
            'is_active' => true, 'has_variants' => true, 'tracking_type' => 'none']);

        // Producto base para consumir en ensamble
        $this->prod['base'] = $this->upsert('products',
            ['organization_id' => $this->orgId, 'sku' => 'PROD-004'], [
            'type' => 'standard', 'name' => 'Docking Station USB-C',
            'description' => 'Base de acople USB-C con HDMI, USB y Ethernet.',
            'price' => 289000, 'purchase_price' => 165000, 'currency' => 'COP',
            'stock' => 60, 'min_stock' => 15, 'max_stock' => 200,
            'category_id' => $this->cat['Tecnología'], 'location_id' => $this->loc['central'],
            'is_active' => true, 'has_variants' => false, 'tracking_type' => 'none']);
    }

    private function variantsBatchesSerials(): void
    {
        // Opciones de variante del polo
        $optTalla = $this->upsert('product_options', ['product_id' => $this->prod['polo'], 'name' => 'Talla'],
            ['position' => 1, 'values' => json_encode(['S', 'M', 'L'])]);
        $this->upsert('product_options', ['product_id' => $this->prod['polo'], 'name' => 'Color'],
            ['position' => 2, 'values' => json_encode(['Azul', 'Negro'])]);

        // 2 variantes
        $this->upsert('product_variants', ['product_id' => $this->prod['polo'], 'sku' => 'PROD-003-S-AZ'], [
            'organization_id' => $this->orgId, 'title' => 'Talla S / Azul',
            'option_values' => json_encode(['Talla' => 'S', 'Color' => 'Azul']),
            'price' => 65000, 'purchase_price' => 32000, 'stock' => 45, 'min_stock' => 5,
            'is_active' => true, 'requires_shipping' => true, 'weight_unit' => 'kg', 'position' => 1]);
        $this->upsert('product_variants', ['product_id' => $this->prod['polo'], 'sku' => 'PROD-003-M-NG'], [
            'organization_id' => $this->orgId, 'title' => 'Talla M / Negro',
            'option_values' => json_encode(['Talla' => 'M', 'Color' => 'Negro']),
            'price' => 65000, 'purchase_price' => 32000, 'stock' => 30, 'min_stock' => 5,
            'is_active' => true, 'requires_shipping' => true, 'weight_unit' => 'kg', 'position' => 2]);

        // 2 lotes del papel
        $this->upsert('product_batches', ['organization_id' => $this->orgId, 'product_id' => $this->prod['papel'], 'batch_number' => 'LOTE-2026-A'], [
            'quantity' => 120, 'manufactured_date' => now()->subDays(30)->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(), 'notes' => 'Lote principal en bodega norte.']);
        $this->upsert('product_batches', ['organization_id' => $this->orgId, 'product_id' => $this->prod['papel'], 'batch_number' => 'LOTE-2026-B'], [
            'quantity' => 120, 'manufactured_date' => now()->subDays(10)->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(), 'notes' => 'Reposición reciente.']);

        // 2 seriales de las laptops
        $this->upsert('product_serials', ['organization_id' => $this->orgId, 'product_id' => $this->prod['laptop'], 'serial_number' => 'SN-TP-0001'], [
            'status' => 'available', 'notes' => 'En bodega central.']);
        $this->upsert('product_serials', ['organization_id' => $this->orgId, 'product_id' => $this->prod['laptop'], 'serial_number' => 'SN-TP-0002'], [
            'status' => 'available', 'notes' => 'En bodega central.']);
    }

    private function kitsAndAssemblies(): void
    {
        // KIT (stock virtual calculado de componentes)
        $this->prod['kit'] = $this->upsert('products',
            ['organization_id' => $this->orgId, 'sku' => 'KIT-001'], [
            'type' => 'kit', 'name' => 'Kit Bienvenida Corporativa',
            'description' => 'Kit de onboarding: laptop + docking station.',
            'price' => 3490000, 'purchase_price' => 2815000, 'currency' => 'COP',
            'stock' => 0, 'min_stock' => 2, 'max_stock' => 20,
            'category_id' => $this->cat['Tecnología'], 'location_id' => $this->loc['central'],
            'is_active' => true, 'has_variants' => false, 'tracking_type' => 'none']);
        $this->component($this->prod['kit'], $this->prod['laptop'], 1, 1);
        $this->component($this->prod['kit'], $this->prod['base'], 1, 2);

        // ENSAMBLE (consume componentes, produce terminado)
        $this->prod['asm'] = $this->upsert('products',
            ['organization_id' => $this->orgId, 'sku' => 'ASM-001'], [
            'type' => 'assembly', 'name' => 'Estación de Trabajo Básica',
            'description' => 'Ensamble de estación: laptop + docking station preconfigurada.',
            'price' => 3558000, 'purchase_price' => 2815000, 'currency' => 'COP',
            'stock' => 8, 'min_stock' => 2, 'max_stock' => 30,
            'category_id' => $this->cat['Tecnología'], 'location_id' => $this->loc['central'],
            'is_active' => true, 'has_variants' => false, 'tracking_type' => 'none']);
        $this->component($this->prod['asm'], $this->prod['laptop'], 1, 1);
        $this->component($this->prod['asm'], $this->prod['base'], 1, 2);
    }

    private function component(int $parent, int $child, int $qty, int $sort): void
    {
        $this->upsert('product_components',
            ['parent_product_id' => $parent, 'component_product_id' => $child],
            ['quantity' => $qty, 'sort_order' => $sort]);
    }

    // ─────────────────────────── Órdenes de venta ───────────────────────────
    private function orders(): void
    {
        $this->order['o1'] = $this->upsert('orders',
            ['organization_id' => $this->orgId, 'order_number' => 'ORD-0001'], [
            'source' => 'manual', 'customer_id' => $this->cus['condor'],
            'customer_name' => 'Distribuidora El Cóndor S.A.S.', 'customer_email' => 'compras@elcondor.co',
            'status' => 'pending', 'approval_status' => 'approved', 'approved_by' => $this->userId,
            'approved_at' => $this->ts(),
            'subtotal' => 6600000, 'tax' => 1254000, 'shipping' => 0, 'total' => 7854000,
            'currency' => 'COP', 'order_date' => $this->ts(), 'created_by' => $this->userId,
            'warehouse_id' => $this->wh['central'], 'notes' => 'Pedido demo — pendiente de despacho.']);
        $this->orderItem($this->order['o1'], $this->prod['laptop'], 'Laptop Lenovo ThinkPad E14', 'PROD-001', 2, 3299000);

        $this->order['o2'] = $this->upsert('orders',
            ['organization_id' => $this->orgId, 'order_number' => 'ORD-0002'], [
            'source' => 'manual', 'customer_id' => $this->cus['andes'],
            'customer_name' => 'Constructora Los Andes Ltda.', 'customer_email' => 'pagos@losandes.co',
            'status' => 'delivered', 'approval_status' => 'approved', 'approved_by' => $this->userId,
            'approved_at' => now()->subDays(8)->toDateTimeString(),
            'subtotal' => 756000, 'tax' => 143640, 'shipping' => 25000, 'total' => 924640,
            'currency' => 'COP', 'order_date' => now()->subDays(10)->toDateTimeString(),
            'shipped_at' => now()->subDays(9)->toDateTimeString(),
            'delivered_at' => now()->subDays(6)->toDateTimeString(),
            'created_by' => $this->userId, 'warehouse_id' => $this->wh['norte'],
            'notes' => 'Pedido demo — entregado y cerrado.']);
        $this->orderItem($this->order['o2'], $this->prod['papel'], 'Resma Papel A4 500 hojas', 'PROD-002', 40, 18900);
    }

    private function orderItem(int $orderId, int $productId, string $name, string $sku, int $qty, float $unit): void
    {
        $sub = $qty * $unit;
        $tax = round($sub * 0.19, 2);
        $this->upsert('order_items',
            ['order_id' => $orderId, 'product_id' => $productId, 'sku' => $sku], [
            'product_name' => $name, 'quantity' => $qty, 'unit_price' => $unit,
            'subtotal' => $sub, 'tax' => $tax, 'total' => $sub + $tax]);
    }

    // ─────────────────────────── Devoluciones ───────────────────────────
    private function returns(): void
    {
        $oi = DB::table('order_items')->where('order_id', $this->order['o2'])->first();

        // Caso 1 — devolución solicitada (pendiente)
        $r1 = $this->upsert('return_orders',
            ['organization_id' => $this->orgId, 'return_number' => 'DEV-0001'], [
            'order_id' => $this->order['o2'], 'type' => 'return', 'status' => 'pending',
            'reason' => 'El cliente reportó empaque dañado en 5 resmas.',
            'notes' => 'Pendiente de aprobación por bodega.', 'refund_amount' => 93396,
            'processed_by' => $this->userId]);
        if ($oi) {
            $this->upsert('return_order_items',
                ['return_order_id' => $r1, 'product_id' => $this->prod['papel']], [
                'order_item_id' => $oi->id, 'quantity' => 5, 'condition' => 'damaged', 'restock' => false]);
        }

        // Caso 2 — devolución completada
        $r2 = $this->upsert('return_orders',
            ['organization_id' => $this->orgId, 'return_number' => 'DEV-0002'], [
            'order_id' => $this->order['o1'], 'type' => 'exchange', 'status' => 'completed',
            'reason' => 'Cambio por modelo superior solicitado por el cliente.',
            'notes' => 'Reemplazo entregado, diferencia facturada.', 'refund_amount' => 0,
            'processed_by' => $this->userId, 'completed_at' => now()->subDays(2)->toDateTimeString()]);
        if ($oi) {
            $this->upsert('return_order_items',
                ['return_order_id' => $r2, 'product_id' => $this->prod['papel']], [
                'order_item_id' => $oi->id, 'quantity' => 2, 'condition' => 'new', 'restock' => true]);
        }
    }

    // ─────────────────────────── Órdenes de compra ───────────────────────────
    private function purchaseOrders(): void
    {
        $po1 = $this->upsert('purchase_orders',
            ['organization_id' => $this->orgId, 'po_number' => 'OC-0001'], [
            'supplier_id' => $this->sup['tecno'], 'created_by' => $this->userId,
            'status' => 'draft', 'order_date' => now()->toDateString(),
            'expected_date' => now()->addDays(14)->toDateString(),
            'subtotal' => 13250000, 'tax' => 2517500, 'shipping' => 120000, 'total' => 15887500,
            'currency' => 'COP', 'notes' => 'Borrador — reposición de laptops.']);
        $this->poItem($po1, $this->prod['laptop'], 'Laptop Lenovo ThinkPad E14', 'PROD-001', 5, 2650000, 0);

        $po2 = $this->upsert('purchase_orders',
            ['organization_id' => $this->orgId, 'po_number' => 'OC-0002'], [
            'supplier_id' => $this->sup['papel'], 'created_by' => $this->userId,
            'status' => 'received', 'order_date' => now()->subDays(20)->toDateString(),
            'expected_date' => now()->subDays(6)->toDateString(),
            'received_date' => now()->subDays(5)->toDateString(),
            'subtotal' => 2300000, 'tax' => 437000, 'shipping' => 80000, 'total' => 2817000,
            'currency' => 'COP', 'notes' => 'Recibida completa por bodega norte.']);
        $this->poItem($po2, $this->prod['papel'], 'Resma Papel A4 500 hojas', 'PROD-002', 200, 11500, 200);
    }

    private function poItem(int $poId, int $prodId, string $name, string $sku, int $qty, float $cost, int $received): void
    {
        $sub = $qty * $cost;
        $tax = round($sub * 0.19, 2);
        $this->upsert('purchase_order_items',
            ['purchase_order_id' => $poId, 'product_id' => $prodId], [
            'product_name' => $name, 'sku' => $sku, 'quantity_ordered' => $qty,
            'quantity_received' => $received, 'unit_cost' => $cost, 'subtotal' => $sub,
            'tax' => $tax, 'total' => $sub + $tax]);
    }

    // ─────────────────────────── Transferencias ───────────────────────────
    private function stockTransfers(): void
    {
        $t1 = $this->upsert('stock_transfers',
            ['organization_id' => $this->orgId, 'transfer_number' => 'TR-0001'], [
            'from_location_id' => $this->loc['central'], 'to_location_id' => $this->loc['norte'],
            'from_warehouse_id' => $this->wh['central'], 'to_warehouse_id' => $this->wh['norte'],
            'is_inter_warehouse' => true, 'transferred_by' => $this->userId,
            'status' => 'in_transit', 'shipping_method' => 'Transportadora Nacional',
            'tracking_number' => 'TRK-778899', 'shipped_at' => now()->subDays(2)->toDateTimeString(),
            'estimated_arrival' => now()->addDays(2)->toDateTimeString(),
            'notes' => 'Demo — en tránsito Bogotá → Medellín.']);
        $this->upsert('stock_transfer_items', ['stock_transfer_id' => $t1, 'product_id' => $this->prod['papel']],
            ['quantity' => 40, 'notes' => 'Reposición de consumibles.']);

        $t2 = $this->upsert('stock_transfers',
            ['organization_id' => $this->orgId, 'transfer_number' => 'TR-0002'], [
            'from_location_id' => $this->loc['norte'], 'to_location_id' => $this->loc['central'],
            'from_warehouse_id' => $this->wh['norte'], 'to_warehouse_id' => $this->wh['central'],
            'is_inter_warehouse' => true, 'transferred_by' => $this->userId,
            'status' => 'completed', 'shipping_method' => 'Mensajería interna',
            'tracking_number' => 'TRK-112233', 'shipped_at' => now()->subDays(12)->toDateTimeString(),
            'completed_at' => now()->subDays(9)->toDateTimeString(),
            'notes' => 'Demo — completada.']);
        $this->upsert('stock_transfer_items', ['stock_transfer_id' => $t2, 'product_id' => $this->prod['base']],
            ['quantity' => 10, 'notes' => 'Consolidación en bodega central.']);
    }

    // ─────────────────────────── Auditorías ───────────────────────────
    private function stockAudits(): void
    {
        $a1 = $this->upsert('stock_audits',
            ['organization_id' => $this->orgId, 'audit_number' => 'AUD-0001'], [
            'name' => 'Conteo cíclico — Bodega Central', 'description' => 'Auditoría de alta rotación.',
            'status' => 'in_progress', 'audit_type' => 'cycle',
            'warehouse_location_id' => $this->loc['central'],
            'started_at' => now()->subDay()->toDateTimeString(),
            'created_by' => $this->userId, 'notes' => 'Demo — conteo en curso.']);
        $this->auditItem($a1, $this->prod['laptop'], 15, 14);
        $this->auditItem($a1, $this->prod['base'], 60, 60);

        $a2 = $this->upsert('stock_audits',
            ['organization_id' => $this->orgId, 'audit_number' => 'AUD-0002'], [
            'name' => 'Conteo completo — Bodega Norte', 'description' => 'Auditoría general.',
            'status' => 'completed', 'audit_type' => 'full',
            'warehouse_location_id' => $this->loc['norte'],
            'started_at' => now()->subDays(5)->toDateTimeString(),
            'completed_at' => now()->subDays(4)->toDateTimeString(),
            'created_by' => $this->userId, 'notes' => 'Demo — completada con diferencias ajustadas.']);
        $this->auditItem($a2, $this->prod['papel'], 240, 238);
        $this->auditItem($a2, $this->prod['polo'], 0, 0);
    }

    private function auditItem(int $auditId, int $prodId, int $system, int $counted): void
    {
        $this->upsert('stock_audit_items',
            ['stock_audit_id' => $auditId, 'product_id' => $prodId], [
            'system_quantity' => $system, 'counted_quantity' => $counted,
            'discrepancy' => $counted - $system,
            'status' => $counted === $system ? 'verified' : 'counted',
            'counted_by' => $this->userId, 'counted_at' => $this->ts()]);
    }

    // ─────────────────────────── Órdenes de trabajo ───────────────────────────
    private function workOrders(): void
    {
        $w1 = $this->upsert('work_orders',
            ['organization_id' => $this->orgId, 'work_order_number' => 'OT-0001'], [
            'product_id' => $this->prod['asm'], 'created_by' => $this->userId,
            'warehouse_id' => $this->wh['central'], 'quantity' => 8, 'quantity_produced' => 8,
            'status' => 'completed', 'started_at' => now()->subDays(6)->toDateTimeString(),
            'completed_at' => now()->subDays(3)->toDateTimeString(),
            'notes' => 'Demo — producción completada.']);
        $this->woItem($w1, $this->prod['laptop'], 8, 8);
        $this->woItem($w1, $this->prod['base'], 8, 8);

        $w2 = $this->upsert('work_orders',
            ['organization_id' => $this->orgId, 'work_order_number' => 'OT-0002'], [
            'product_id' => $this->prod['asm'], 'created_by' => $this->userId,
            'warehouse_id' => $this->wh['central'], 'quantity' => 5, 'quantity_produced' => 0,
            'status' => 'pending', 'notes' => 'Demo — pendiente de iniciar.']);
        $this->woItem($w2, $this->prod['laptop'], 5, 0);
        $this->woItem($w2, $this->prod['base'], 5, 0);
    }

    private function woItem(int $woId, int $prodId, float $req, float $consumed): void
    {
        $this->upsert('work_order_items',
            ['work_order_id' => $woId, 'product_id' => $prodId], [
            'quantity_required' => $req, 'quantity_consumed' => $consumed]);
    }

    // ─────────────────────────── Reportes ───────────────────────────
    private function reports(): void
    {
        $this->upsert('saved_reports',
            ['organization_id' => $this->orgId, 'name' => 'Productos con Stock Bajo'], [
            'created_by' => $this->userId, 'description' => 'Productos por debajo del stock mínimo.',
            'data_source' => 'products', 'columns' => json_encode(['name', 'sku', 'stock', 'min_stock']),
            'filters' => json_encode([['field' => 'stock', 'operator' => 'lt', 'value' => 'min_stock']]),
            'sort' => json_encode(['field' => 'stock', 'direction' => 'asc']),
            'chart_type' => 'bar', 'chart_field' => 'stock', 'is_shared' => true]);

        $this->upsert('saved_reports',
            ['organization_id' => $this->orgId, 'name' => 'Ventas por Estado'], [
            'created_by' => $this->userId, 'description' => 'Órdenes agrupadas por estado.',
            'data_source' => 'orders', 'columns' => json_encode(['order_number', 'customer_name', 'status', 'total']),
            'sort' => json_encode(['field' => 'order_date', 'direction' => 'desc']),
            'chart_type' => 'pie', 'chart_field' => 'status', 'is_shared' => true]);
    }

    // ─────────────────────────── Webhooks ───────────────────────────
    private function webhooks(): void
    {
        $this->upsert('webhooks',
            ['organization_id' => $this->orgId, 'name' => 'Notificador de Pedidos'], [
            'url' => 'https://hooks.example.com/inventoros/orders',
            'secret' => Str::random(64),
            'events' => json_encode(['order.created', 'order.updated']),
            'is_active' => true, 'created_by' => $this->userId]);

        $this->upsert('webhooks',
            ['organization_id' => $this->orgId, 'name' => 'Sincronización de Inventario'], [
            'url' => 'https://hooks.example.com/inventoros/inventory',
            'secret' => Str::random(64),
            'events' => json_encode(['product.updated', 'stock.adjusted']),
            'is_active' => true, 'created_by' => $this->userId]);
    }
}
