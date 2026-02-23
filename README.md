# Rapidshyp Enhanced for WooCommerce

A high-performance shipping integration for WooCommerce that connects your store to the Rapidshyp aggregator. This plugin was developed to bridge the gap for stores needing real-time serviceability checks and automated order syncing.

## 🚀 Key Features
- **Real-time Serviceability:** Pincode check on Cart/Checkout pages.
- **Auto-Autofill:** City/State population via Pincode API.
- **Automated Fulfillment:** Instant order creation in Rapidshyp upon purchase.
- **Tracking Management:** Automatic AWB and tracking token storage.
- **Advanced Shipping Logic:** Custom COD fees and Free Shipping threshold notices.
- **Webhook Integration:** Real-time order status updates from Rapidshyp.

## 🛠 Installation
1. Download this repository as a .zip file.
2. Upload to your WordPress site via `Plugins > Add New > Upload`.
3. Go to `WooCommerce > Rapidshyp Enhanced` to enter your API credentials.

## 👨‍💻 Technical Highlights
- Developed using **Generative AI orchestration** and manual logic refinement.
- Includes a custom **State-Code Mapping engine** for Indian territories.

- Features a **Debounced AJAX handler** to prevent server strain during pincode entry.

- 🔍 Project Spotlight: Solving the Logistics-UX Gap
The Problem
During the management of a high-volume WooCommerce store, I identified a critical drop-off point: users were reaching the checkout without knowing if their location was serviceable. The shipping aggregator (Rapidshyp) lacked an official plugin with real-time validation, leading to failed orders and manual customer support work.

The Solution: "Rapidshyp Enhanced"
I architected and developed a custom integration using Generative AI orchestration to bridge the Rapidshyp API with the WooCommerce checkout flow.

Technical & SEO Highlights:

Performance Engineering: Resolved a notorious "Select2" infinite loop bug in the checkout script, ensuring page stability and protecting Core Web Vital scores.

AEO-Ready Data: Implemented a custom State-Code mapping engine to ensure 100% address accuracy, crucial for data integrity in automated systems.

Conversion Optimization (CRO): * Added a Dynamic Shipping Progress Bar that visually alerts users how much more they need to spend for free shipping (increasing Average Order Value).

Built a Session-Persistent Pincode Checker so users don't have to re-enter data if they refresh the page.

AI Automation: Leveraged LLMs to write the boilerplate logic, while I focused on manual debugging, security hardening (Nonces/Sanitization), and API endpoint mapping.

The Impact
0% Manual Mapping: Webhooks now automatically sync order statuses back to the site.

Reduced Abandonment: Real-time serviceability checks gave users immediate confidence to purchase.

System Scalability: The plugin is designed to handle high-concurrency requests without server lag.
