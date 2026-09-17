@props(['name'])
<span class="nav-link-icon d-md-none d-lg-inline-block" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
@switch($name)
@case('transaction-group')<path d="M5 3h14v18l-3-2-4 2-4-2-3 2zM8 8h8M8 12h8"/>@break
@case('report-group')<path d="M4 19V9M10 19V5M16 19v-8M22 19H2M4 3h16"/>@break
@case('inventory-group')<path d="M3 7l9-4 9 4-9 4zM3 7v10l9 4 9-4V7M12 11v10"/>@break
@case('inventory-control')<path d="M4 6h16v14H4zM8 6V4h8v2M8 11h8M8 15h5"/>@break
@case('product-master')<path d="M4 7l8-4 8 4-8 4zM4 7v10l8 4 8-4V7M8 13h8"/>@break
@case('sales-order')<path d="M9 11l3 3 8-8M20 12v6a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h9"/>@break
@case('barcode')<path d="M3 5v14M7 5v14M11 5v14M15 5v14M19 5v14M5 5v14M13 5v14"/>@break
@case('price-list')<path d="M7 7h.01M3 3h6l12 12l-6 6L3 9z"/>@break
@case('bundle')<path d="M4 7l8-4 8 4-8 4zM4 7v10l8 4 8-4V7"/><path d="M12 11v10"/>@break
@case('purchasing-group')<path d="M3 4h2l2 11h10l3-8H6M9 20h.01M17 20h.01"/>@break
@case('purchasing-workspace')<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5M16 16l2 2 3-4"/>@break
@case('platform-group')<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6V20h-4v-.08a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1H4v-4h.08a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6V4h4v.08a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.14.37.35.7.6 1H20v4h-.08c-.25.3-.46.63-.52 1z"/>@break
@case('platform-dashboard')<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 9h8V3h-8z"/>@break
@case('dashboard')<path d="M4 4h6v6H4zM14 4h6v10h-6zM4 14h6v6H4zM14 18h6v2h-6z"/>@break
@case('pos')<path d="M4 3h16v18H4zM7 6h10v4H7zM8 15h.01M12 15h.01M16 15h.01M8 18h.01M12 18h.01M16 18h.01"/>@break
@case('approval')<path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>@break
@case('business')<path d="M4 19V9M10 19V5M16 19v-8M22 19H2"/>@break
@case('finance')<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/>@break
@case('operations')<path d="M3 12h4l3-8 4 16 3-8h4"/>@break
@case('tenants')<path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1M14 9h1M9 13h1M14 13h1M10 21v-4h4v4"/>@break
@case('plans')<path d="M4 5h16v14H4zM4 9h16M8 13h3"/>@break
@case('modules')<path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3M8 8h8v8H8z"/>@break
@case('audit')<path d="M9 12l2 2 4-4M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z"/>@break
@case('blog')<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/>@break
@case('integrations')<path d="M8 12h8M12 8v8M5 5l3 3M19 5l-3 3M5 19l3-3M19 19l-3-3"/>@break
@case('health')<path d="M3 12h4l2-6 4 12 2-6h6"/>@break
@default<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>
@endswitch
</svg></span>
