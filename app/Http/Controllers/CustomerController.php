<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quantity;
use App\Mail\receiptMailable;
use Illuminate\Support\Facades\Mail;
use App\Models\Items;
use Carbon\CarbonTimeZone;
use Stripe;
use Stripe\Exception\CardException;
use Stripe\Exception\ApiErrorException;
use PDF;
use App\Models\CustomerAddress;
use App\Models\ProductCategory;

use function PHPUnit\Framework\isEmpty;

class CustomerController extends Controller
{

    public function address(Request $request)
    {
        $address = CustomerAddress::all();
        return view('home.new_address', compact('address'));
    }

    public function create(Request $request)
    {
        return view('home.add_address');
    }

    public function edit(Request $request, $id)
    {
        $address = CustomerAddress::find($id);
        return view('home.edit_address', compact('address'));
    }

    public function saveedit(Request $request)
    {
        $id = $request->input('id');
        $address = CustomerAddress::find($id);
        $address->address = $request->input('address');
        $address->phone_number = $request->input('phone_number');
        $address->save();

        $address = CustomerAddress::all();
        return redirect('new-address');
    }

    public function save(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
            'phone_number' => 'required|string',
        ]);

        // Get the currently logged-in user's ID
        $userId = Auth::id();

        // Create a new CustomerAddress instance and save the address and phone number
        $address = new CustomerAddress();
        $address->u_id = $userId;
        $address->address = $request->input('address');
        $address->phone_number = $request->input('phone_number');
        $address->save();
        $address = CustomerAddress::all();
        return redirect('new-address')->with('success', 'Address saved successfully.');
    }

    public function delete(Request $request, $id)
    {
        // Find the address by its ID
        $address = CustomerAddress::find($id);

        // Check if the address exists
        if ($address) {
            // Delete the address
            $address->delete();

            // Redirect to the 'new-address' view with a success message
            return redirect()->route('newAddress')->with('success', 'Address deleted successfully.');
        }

        return redirect()->back()->with('error', 'Address not found.');
    }

    public function index()
    {
        $products = Product::take(3)->get();
        return view('Customer', compact('products'));
    }
    public function productshow()
    {
        $products = Product::paginate(9);
        $category = ProductCategory::all(); // Assuming you have a "categories" table

        return view('home.showproduct', compact('products', 'category'));
    }

    public function product_details($productId)
    {
        $products = Product::find($productId);
        $category = ProductCategory::find($products->product_category);

        return view('home.product_details', ['products' => $products, 'category' => $category]);
    }
    public function add_cart(Request $request, $id)
    {
        if (Auth::id()) {
            $user = Auth::user();
            $userid = $user->id;
            $cart = Cart::where('user_id', $user->id)->first();

            $product = Product::find($id);

            $product_exist_id = Cart::where('product_id', '=', $id)->where('user_id', '=', $user->id)->get('id')->first();
            if ($product_exist_id) {
                $cart = Cart::find($product_exist_id)->first();
                $quantity = $cart->product_quantity;
                $cart->product_quantity = $cart->product_quantity + $request->quantity;
                $cart->save();
                return redirect()->back()->with('success', 'Item successfully added to cart.');
            } else {
            }

            $cart = new Cart();
            $cart->user_id = $user->id; // Set the user_id
            $cart->product_id = $product->id;
            $cart->product_quantity = $request->quantity;
            $cart->save();

            return redirect()->back()->with('success', 'Item successfully added to cart.');
        } else {
            return redirect('login');
        }
    }

    public function add_cart_details(Request $request, $id)
    {
        if (Auth::id()) {
            $user = Auth::user();
            $userid = $user->id;
            $cart = Cart::where('user_id', $user->id)->first();

            $product = Product::find($id);

            $product_exist_id = Cart::where('product_id', '=', $id)->where('user_id', '=', $user->id)->get('id')->first();
            if ($product_exist_id) {
                $cart = Cart::find($product_exist_id)->first();
                $quantity = $cart->product_quantity;
                $cart->product_quantity = $cart->product_quantity + $request->quantity;
                $cart->save();
                return redirect()->route('show_cart');
            } else {
            }

            $cart = new Cart();
            $cart->user_id = $user->id; // Set the user_id
            $cart->product_id = $product->id;
            $cart->product_quantity = $request->quantity;
            $cart->save();

            return redirect()->route('show_cart');
        } else {
            return redirect('login');
        }
    }

    public function show_cart()
    {
        $id = Auth::user()->id;
        $cart = Cart::where('user_id', $id)->get();
        $productIds = $cart->pluck('product_id')->toArray();
        $products = Product::whereIn('id', $productIds)->get();
        return view('show_cart', compact('cart', 'products'));
    }

    public function remove_cart(Request $request, $id)
    {
        $cart = Cart::find($id);

        if ($cart) {
            $cart->delete();
            return redirect()->back()->with('success', 'Item successfully removed from cart.');
        }

        return redirect()->back()->with('error', 'Item not found.');
    }

    public function payment($id)
    {
        $payment = Payment::where('cart_id', $id);
        return view('payment', compact('payment'));
    }
    public function saveCartDetails(Request $request)
    {
        Cart::where('user_id', Auth::user()->id)->update([
            'address' => $request->address,
            'phone' => $request->phone,
        ]);

        return redirect()->route('address', ['id' => Auth::user()->id])->with('success', 'Cart details saved successfully.');
    }
    public function stripe($token)
    {
        $decryptedToken = decrypt($token);
        $values = explode('/', $decryptedToken);

        $id = $values[0];
        $cid = $values[1];
        $totalprice = $values[2];
        $cart = Cart::where('user_id', $id)->get();
        $productIds = $cart->pluck('product_id')->toArray();
        $products = Product::whereIn('id', $productIds)->get();

        // Perform any additional validations or checks here

        return view('home.stripe', compact('id', 'cid', 'totalprice', 'cart', 'products'));
    }

    public function stripePost(Request $request)
    {
        $id = $request->id; // Get the user ID
        $cid = $request->cid; // Get the cid
        $totalPrice = $request->totalprice;

        // Clear the session data
        session()->forget('stripe_data');

        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        // $totalprice = 3;
        Stripe\Charge::create([
            "amount" => $totalPrice * 100,
            "currency" => "myr",
            "source" => $request->stripeToken,
            "description" => "Thanks for payment."
        ]);

        $payment = new Payment();
        $payment->address = $request->address;
        $payment->phone = $request->phone;
        $payment->cardname = $request->cardname;
        $payment->cardnumber = $request->cardnumber;
        $payment->save();

        $order = new Order();
        $order->user_id = $id;
        $order->payment_id = $payment->id;
        $order->totalprice = $totalPrice;
        $order->save();

        $pid = Cart::where('product_id', $id)->get();
        $carts = Cart::where('user_id', $id)->get();

        foreach ($carts as $cart) {

            $product = Product::find($cart->product_id);
            if ($product) {
                $product->product_quantity -= $cart->product_quantity; // Update the product quantity
                $product->save();
            }

            $item = new Items();
            $item->order_id = $order->id;
            $item->product_id = $cart->product_id;
            $item->product_quantity = $cart->product_quantity;
            $item->save();
            $cart->delete();
        }
        Session::flash('success', 'Payment successful!');
        return redirect(url('/orderhistory'));
    }


    public function order_details($id)
    {
        $id = Auth::user()->id;
        $cart = Cart::where('user_id', $id)->get();
        $productIds = $cart->pluck('product_id')->toArray();
        $products = Product::whereIn('id', $productIds)->get();
        return view('home.orderdetails', compact('cart', 'products'));
    }
    public function print_pdf($id)
    {
        $user = Auth::user();
        $userid = $user->id;
        $cart = Cart::where('user_id', $userid)->get();
        $productIds = $cart->pluck('product_id');
        $products = Product::whereIn('id', $productIds)->get();

        $totalPrice = 0;
        foreach ($cart as $item) {
            $product = $products->firstWhere('id', $item->product_id);
            if ($product) {
                $totalPrice += $item->product_quantity * $product->price;
                $item->product_name = $product->product_name;
                $item->product_price = $product->product_sellingprice; // Assign the product price to the item in the cart
            }
        }

        $receiptNumber = 'REC' . uniqid();

        $data = [
            'cart' => $cart,
            //'products' => $products,
            'totalPrice' => $totalPrice,
            'receiptNumber' => $receiptNumber,
        ];

        return PDF::loadView('home.pdf', $data)->stream('invoice.pdf');
        // return view('home.pdf', compact('cart'));
    }
    public function mailReceipt($id)
    {
        $user = Auth::user();
        $userid = $user->id;
        $cart = Cart::where('user_id', $userid)->get();
        $productIds = $cart->pluck('product_id');
        $products = Product::whereIn('id', $productIds)->get();


        $totalPrice = 0;
        foreach ($cart as $item) {
            $product = $products->firstWhere('id', $item->product_id);
            if ($product) {
                $totalPrice += $item->product_quantity * $product->price;
                $item->product_name = $product->product_name;
                $item->product_price = $product->product_sellingprice; // Assign the product price to the item in the cart
            }
        }
        //dd($totalPrice);
        $receiptNumber = 'REC' . uniqid();

        $data = [
            'cart' => $cart,
            //'products' => $products,
            'totalPrice' => $totalPrice,
            'receiptNumber' => $receiptNumber,
        ];

        $pdf = PDF::loadView('home.pdf', $data);
        $pdfData = $pdf->output();


        $userEmail = $user->email;


        Mail::raw('Here is your receipt.', function ($message) use ($pdfData, $userEmail) {
            $message->to($userEmail)
                ->subject('Receipt')
                ->attachData($pdfData, 'invoice.pdf');
        });


        return response('Receipt sent via email.');
    }
    // public function print_pdf($id)
    // {
    //     $cart = Cart::where('user_id', $id)->get();

    //     //$pdf = PDF::loadView('home.pdf', compact('cart'));

    //     //return $pdf->download('invoice.pdf');
    //     return view('home.pdf', compact('cart'));
    // }
    public function searchdata(Request $request)
    {
        if ($request->ajax()) {
            $query = $request->search;
            $selectedCategories = $request->categories;
            $page = $request->page; // Get the current page number

            $data = Product::query();

            if (!empty($query)) {
                $data = $data->where('product_name', 'LIKE', '%' . $query . "%");
            }

            if (!empty($selectedCategories)) {
                $data = $data->whereIn('product_category', $selectedCategories);
            }

            $paginator = $data->paginate(9, ['*'], 'page', $page);
            $items = $paginator->items(); // Get the underlying array of items from the paginator

            $output = '';
            foreach ($items as $index => $product) {
                // Start a new row every three products
                if ($index % 3 === 0) {
                    $output .= '<div class="row">';
                }

                $output .= '
            <div class="col-sm-4">
                <div class="box fixed-box">
                    <div class="option_container">
                        <div class="options">
                            <a href="' . url('product_details', $product->id) . '" class="option1">
                                Product Details
                            </a>
                            <form action="' . url('add_cart', $product->id) . '" method="POST">
                                ' . csrf_field() . '
                                <div class="row">
                                    <div class="col-md-4">
                                        <input type="number" name="quantity" value="1" min="1" max="' . $product->product_quantity . '" style="width: 60px;">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="submit" value="Add to Cart">
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="img-box">
                        <img src="' . $product->product_img1 . '" alt="">
                    </div>
                    <div class="detail-box">
                        <h5>' . $product->product_name . '</h5>
                        <h6>RM' . $product->product_sellingprice . '</h6>
                    </div>
                </div>
            </div>';

                // Close the row after three products or when it's the last item in the current page
                if (($index + 1) % 3 === 0 || ($index + 1) === count($items)) {
                    $output .= '</div>';
                }
            }

            // Check if there are no search results
            if (empty($output)) {
                $output = '<h2>No data found</h2>';
            }

            $pagination = $paginator->links('pagination::bootstrap-5')->toHtml(); // Get the pagination links HTML

            return response()->json([
                'output' => $output,
                'pagination' => $pagination,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ]);
        }
    }
}
