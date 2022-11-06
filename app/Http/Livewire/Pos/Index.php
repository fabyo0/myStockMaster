<?php

namespace App\Http\Livewire\Pos;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\SalePayment;
use Gloudemans\Shoppingcart\Facades\Cart;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class Index extends Component
{
    use LivewireAlert;

    public $listeners = [
        'refreshPos', 'productSelected', 'refreshIndex',
        'discountModalRefresh', 'checkoutModal',
        'refreshCustomers'
        ];  

    public $cart_instance;
    public $customers;
    public $global_discount;
    public $global_tax;
    public $shipping;
    public $quantity;
    public $check_quantity;
    public $discount_type;
    public $item_discount;
    public $data;
    public $customer_id;
    public $total_amount;
    public $checkoutModal;
    public $product;
    public $price;
    public $paid_amount;
    public $tax_percentage;
    public $discount_percentage;
    public $discount_amount;
    public $tax_amount;
    public $grand_total;
    public $shipping_amount;
    public $payment_method;
    public $note;
    public $refreshCustomers;
    public $refreshIndex;
    public array $listsForFields = [];

    public function rules()
    {
        return [
            'customer_id' => 'required|numeric',
            'tax_percentage' => 'required|integer|min:0|max:100',
            'discount_percentage' => 'required|integer|min:0|max:100',
            'shipping_amount' => 'numeric',
            'total_amount' => 'required|numeric',
            'paid_amount' => 'numeric',
            'note' => 'nullable|string|max:1000'
        ];
    }

    protected function initListsForFields(): void
    {
        $this->listsForFields['customers'] = Customer::pluck('name', 'id')->toArray();
    }
    
    public function refreshCustomers()
    {
        $this->initListsForFields();
    }

    public function refreshIndex()
    {
        $this->reset();
    }

    public function mount($cartInstance) {
        
        $this->cart_instance = $cartInstance;
        $this->global_discount = 0;
        $this->global_tax = 0;
        $this->shipping = 0.00;
        $this->check_quantity = [];
        $this->quantity = [];
        $this->discount_type = [];
        $this->item_discount = [];
        $this->payment_method = 'cash';
        
        $this->tax_percentage = 0;
        $this->discount_percentage = 0;
        $this->shipping_amount = 0;
        $this->paid_amount = 0;

        $this->initListsForFields();    
    }

    public function hydrate() {
        if ($this->payment_method == 'cash') {
            $this->paid_amount = $this->total_amount;
        }
        $this->total_amount = $this->calculateTotal();
        $this->updatedCustomerId();
    }

    public function render() {


        $cart_items = Cart::instance($this->cart_instance)->content();

        return view('livewire.pos.index', [
            'cart_items' => $cart_items
        ]);
    }

    public function store() {

        try{
        $this->validate();

        $due_amount = $this->total_amount - $this->paid_amount;

        if ($due_amount == $this->total_amount) {
            $payment_status = '3';
        } elseif ($due_amount > 0) {
            $payment_status = '2';
        } else {
            $payment_status = '1';
        }
        // dd(Cart::instance('sale')->content()); 
        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'reference' => 'PSL',
            'customer_id' => $this->customer_id,
            'tax_percentage' => $this->tax_percentage,
            'discount_percentage' => $this->discount_percentage,
            'shipping_amount' => $this->shipping_amount * 100,
            'paid_amount' => $this->paid_amount * 100,
            'total_amount' => $this->total_amount * 100,
            'due_amount' => $due_amount * 100,
            'status' => '2',
            'payment_status' => $payment_status,
            'payment_method' => $this->payment_method,
            'note' => $this->note,
            'tax_amount' => Cart::instance('sale')->tax() * 100,
            'discount_amount' => Cart::instance('sale')->discount() * 100,
        ]);

        // foreach ($this->cart_instance as cart_items) {}
        foreach (Cart::instance('sale')->content() as $cart_item) {
            SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $cart_item->id,
                'name' => $cart_item->name,
                'code' => $cart_item->options->code,
                'quantity' => $cart_item->qty,
                'price' => $cart_item->price * 100,
                'unit_price' => $cart_item->options->unit_price * 100,
                'sub_total' => $cart_item->options->sub_total * 100,
                'product_discount_amount' => $cart_item->options->product_discount * 100,
                'product_discount_type' => $cart_item->options->product_discount_type,
                'product_tax_amount' => $cart_item->options->product_tax * 100,
            ]);

            $product = Product::findOrFail($cart_item->id);
            $product->update([
                'quantity' => $product->quantity - $cart_item->qty
            ]);
            
        }

        Cart::instance('sale')->destroy();

        if ($sale->paid_amount > 0) {
            SalePayment::create([
                'date' => now()->format('Y-m-d'),
                'reference' => 'INV/'.$sale->reference,
                'amount' => $sale->paid_amount,
                'sale_id' => $sale->id,
                'payment_method' => $this->payment_method
            ]);
        }

        $this->alert('success', 'Sale created successfully!');

        $this->checkoutModal = false;

        } catch (\Exception $e) {
            $this->alert('error', $e->getMessage());
        }
}

    public function refreshPos() {
        $this->reset();
    }

    public function proceed() {
        if ($this->customer_id != null) {
            $this->checkoutModal = true;
        } else {
            $this->alert('error', __('Please select a customer!'));
        }
    }

    public function calculateTotal() {
        return Cart::instance($this->cart_instance)->total() + $this->shipping;
    }

    public function resetCart() {
        Cart::instance($this->cart_instance)->destroy();
    }

    public function productSelected($product) {
        $cart = Cart::instance($this->cart_instance);

        $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
            return $cartItem->id == $product['id'];
        });

        if ($exists->isNotEmpty()) {
            $this->alert('error', 'Product already added to cart!');

            return;
        }

        $cart->add([
            'id'      => $product['id'],
            'name'    => $product['name'],
            'qty'     => 1,
            'price'   => $this->calculate($product)['price'],
            'weight'  => 1,
            'options' => [
                'product_discount'      => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total'             => $this->calculate($product)['sub_total'],
                'code'                  => $product['code'],
                'stock'                 => $product['quantity'],
                'unit'                  => $product['unit'],
                'product_tax'           => $this->calculate($product)['product_tax'],
                'unit_price'            => $this->calculate($product)['unit_price']
            ]
        ]);

        $this->check_quantity[$product['id']] = $product['quantity'];
        $this->quantity[$product['id']] = 1;
        $this->discount_type[$product['id']] = 'fixed';
        $this->item_discount[$product['id']] = 0;
        $this->total_amount = $this->calculateTotal();
    }

    public function removeItem($row_id) {
        Cart::instance($this->cart_instance)->remove($row_id);
    }

    public function updatedGlobalTax() {
        Cart::instance($this->cart_instance)->setGlobalTax((integer)$this->global_tax);
    }

    public function updatedGlobalDiscount() {
        Cart::instance($this->cart_instance)->setGlobalDiscount((integer)$this->global_discount);
    }

    public function updateQuantity($row_id, $product_id) {
        if ($this->check_quantity[$product_id] < $this->quantity[$product_id]) {
           
            $this->alert('error', 'Quantity not available in stock!');

            return;
        }

        Cart::instance($this->cart_instance)->update($row_id, $this->quantity[$product_id]);

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        Cart::instance($this->cart_instance)->update($row_id, [
            'options' => [
                'sub_total'             => $cart_item->price * $cart_item->qty,
                'code'                  => $cart_item->options->code,
                'stock'                 => $cart_item->options->stock,
                'unit'                  => $cart_item->options->unit,
                'product_tax'           => $cart_item->options->product_tax,
                'unit_price'            => $cart_item->options->unit_price,
                'product_discount'      => $cart_item->options->product_discount,
                'product_discount_type' => $cart_item->options->product_discount_type,
            ]
        ]);
    }
    public function updatePrice($row_id, $product_id) {

        Cart::instance($this->cart_instance)->update($row_id, $this->price[$product_id]);

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);


        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $this->price[$product_id],
            'options' => [
                'sub_total'             => $cart_item->price * $cart_item->qty,
                'code'                  => $cart_item->options->code,
                'stock'                 => $cart_item->options->stock,
                'unit'                  => $cart_item->options->unit,
                'product_tax'           => $cart_item->options->product_tax,
                'unit_price'            => $cart_item->options->unit_price,
                'product_discount'      => $cart_item->options->product_discount,
                'product_discount_type' => $cart_item->options->product_discount_type,
            ]
        ]);
    }

    public function updatedDiscountType($value, $name) {
        $this->item_discount[$name] = 0;
    }

    public function discountModalRefresh($product_id, $row_id) {
        $this->updateQuantity($row_id, $product_id);
    }

    public function setProductDiscount($row_id, $product_id) {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        if ($this->discount_type[$product_id] == 'fixed') {
            Cart::instance($this->cart_instance)
                ->update($row_id, [
                    'price' => ($cart_item->price + $cart_item->options->product_discount) - $this->item_discount[$product_id]
                ]);

            $discount_amount = $this->item_discount[$product_id];

            $this->updateCartOptions($row_id, $product_id, $cart_item, $discount_amount);
        } elseif ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = ($cart_item->price + $cart_item->options->product_discount) * ($this->item_discount[$product_id] / 100);

            Cart::instance($this->cart_instance)
                ->update($row_id, [
                    'price' => ($cart_item->price + $cart_item->options->product_discount) - $discount_amount
                ]);

            $this->updateCartOptions($row_id, $product_id, $cart_item, $discount_amount);
        }
        $this->alert('success', 'Product discount set successfully!');
    }

    public function calculate($product) {
        $price = 0;
        $unit_price = 0;
        $product_tax = 0;
        $sub_total = 0;

        if ($product['tax_type'] == 1) {
            $price = $product['price'] + ($product['price'] * ($product['order_tax'] / 100));
            $unit_price = $product['price'];
            $product_tax = $product['price'] * ($product['order_tax'] / 100);
            $sub_total = $product['price'] + ($product['price'] * ($product['order_tax'] / 100));
        } elseif ($product['tax_type'] == 2) {
            $price = $product['price'];
            $unit_price = $product['price'] - ($product['price'] * ($product['order_tax'] / 100));
            $product_tax = $product['price'] * ($product['order_tax'] / 100);
            $sub_total = $product['price'];
        } else {
            $price = $product['price'];
            $unit_price = $product['price'];
            $product_tax = 0.00;
            $sub_total = $product['price'];
        }

        return ['price' => $price, 'unit_price' => $unit_price, 'product_tax' => $product_tax, 'sub_total' => $sub_total];
    }

    public function updateCartOptions($row_id, $product_id, $cart_item, $discount_amount) {
        Cart::instance($this->cart_instance)->update($row_id, ['options' => [
            'sub_total'             => $cart_item->price * $cart_item->qty,
            'code'                  => $cart_item->options->code,
            'stock'                 => $cart_item->options->stock,
            'unit'                 => $cart_item->options->unit,
            'product_tax'           => $cart_item->options->product_tax,
            'unit_price'            => $cart_item->options->unit_price,
            'product_discount'      => $this->discount_amount,
            'product_discount_type' => $this->discount_type[$product_id],
        ]]);
    }

  
}