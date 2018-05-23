# WooCommerce plugin for LinkPay

## Installation

#### Requirements

- PHP >= 5.4
- [WordPress 4.x](https://wordpress.org/)
- [WooCommerce 3.x](https://woocommerce.com/)
- Registration in the cabinet of the supplier [Linkpay](http://www.linkpay.id/)

#### GitHub

Download the plugin as [ZIP archive](https://github.com/LinkPay/woocommerce-payment-gateway/releases/latest)

Download the plugin in WordPress

![Upload plugin](images/upload-plugin.png)

... and install it

![Install plugin from ZIP](images/install-from-zip.png)

Activate the plug-in after installation

![Activate plugin](images/activate-plugin.png)

#### That Apache did not ignore the header `Authorization` you must upload a file `.htaccess` сabout the following content:

```
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
```

in the root directory of the site

Open the WooCommerce settings page

![WooCommerce Settings page](images/woocommerce-settings.png)

Click the `Checkout`

![Checkout Tab](images/checkout-tab.png)

Click the `Payme` and enter the required data.

![Payme Settings](images/payme-settings.png)

Copy your `Endpoint URL` and enter it in the cabinet of the LinkPay provider.

![Set Endpoint URL](images/endpoint-url.png)
