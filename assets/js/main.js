// Global JS logic
$(document).ready(function() {

    // Add smooth scrolling to all links
    $("a").on('click', function(event) {
        if (this.hash !== "") {
            event.preventDefault();
            var hash = this.hash;
            $('html, body').animate({
                scrollTop: $(hash).offset().top
            }, 800, function(){
                window.location.hash = hash;
            });
        }
    });

    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    // Update cart badge on page load
    updateCartBadge();
    
    // Check Auth Status and Update UI
    checkAuthAndUpdateUI();
    
    // Inject Floating LINE Button
    injectLineButton();
});

function injectLineButton() {
    // Only inject if not already present
    if (document.getElementById('floating-line-btn')) return;

    const style = document.createElement('style');
    style.textContent = `
        .floating-line-btn {
            position: fixed;
            bottom: 25px;
            right: 25px;
            background-color: #00B900;
            color: #FFF;
            border-radius: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            font-size: 18px;
            font-weight: 600;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            border: 3px solid #FFF;
            z-index: 9999;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            animation: linePulse 2s infinite;
        }
        .floating-line-btn i {
            font-size: 28px;
        }
        .floating-line-btn:hover {
            transform: scale(1.05) translateY(-3px);
            color: #FFF;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        @keyframes linePulse {
            0% { box-shadow: 0 0 0 0 rgba(0, 185, 0, 0.6); }
            70% { box-shadow: 0 0 0 15px rgba(0, 185, 0, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 185, 0, 0); }
        }
        @media (max-width: 768px) {
            .floating-line-btn {
                bottom: 20px;
                right: 20px;
                padding: 8px 16px;
                font-size: 16px;
            }
            .floating-line-btn i {
                font-size: 24px;
            }
        }
    `;
    document.head.appendChild(style);

    const a = document.createElement('a');
    a.id = 'floating-line-btn';
    a.className = 'floating-line-btn';
    a.href = '#';
    a.title = 'แชทกับเราผ่าน LINE';
    a.innerHTML = '<i class="fab fa-line"></i> ติดต่อเรา / รับซื้อโคเนื้อ';
    a.setAttribute('data-bs-toggle', 'modal');
    a.setAttribute('data-bs-target', '#lineQrModal');
    
    document.body.appendChild(a);

    // Inject the Modal HTML
    if (!document.getElementById('lineQrModal')) {
        const lineUrl = 'https://line.me/ti/p/f3tBSZApa1';
        const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(lineUrl);
        
        const modalHtml = `
        <style>
            .line-oa-header {
                height: 140px;
                background: url('https://images.unsplash.com/photo-1596733430284-f743727521a5?q=80&w=800&auto=format&fit=crop') center center/cover no-repeat;
                position: relative;
            }
            .line-oa-profile {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                border: 4px solid #fff;
                position: absolute;
                bottom: -40px;
                left: 20px;
                background: url('https://images.unsplash.com/photo-1546447147-3bfc38fac9c4?q=80&w=200&auto=format&fit=crop') center center/cover no-repeat;
                background-color: #fff;
            }
            .line-oa-info {
                padding: 50px 20px 20px;
                text-align: left;
            }
        </style>
        <div class="modal fade" id="lineQrModal" tabindex="-1" aria-labelledby="lineQrModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content shadow-lg border-0" style="border-radius: 15px; overflow: hidden;">
                    
                    <div class="line-oa-header">
                        <div class="position-absolute top-0 start-0 p-2 text-white fw-bold" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.8); font-size: 0.8rem; letter-spacing: 1px;">
                            LINE OFFICIAL ACCOUNT
                        </div>
                        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close" style="filter: drop-shadow(0px 0px 2px rgba(0,0,0,0.8));"></button>
                        <div class="line-oa-profile"></div>
                    </div>
                    
                    <div class="line-oa-info bg-white">
                        <h5 class="fw-bold mb-1 d-flex align-items-center">
                            Beef Brother <i class="fas fa-check-circle text-primary ms-2" style="font-size: 0.9rem;" title="Verified"></i>
                        </h5>
                        <p class="text-muted small mb-4">จำหน่ายเนื้อนำเข้า</p>
                        
                        <div class="row text-center mt-3 border-top pt-4">
                            <div class="col-6 border-end">
                                <h6 class="fw-bold mb-3">กดเพื่อแชท</h6>
                                <a href="${lineUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-dark rounded-pill px-4 mb-2">
                                    <i class="fas fa-comment-dots me-2"></i> Chat
                                </a>
                                <p class="text-muted" style="font-size: 0.75rem;">เปิดแอปพลิเคชัน LINE</p>
                            </div>
                            <div class="col-6">
                                <h6 class="fw-bold mb-2">สแกน QR Code</h6>
                                <img src="${qrUrl}" alt="LINE QR Code" class="img-fluid border p-1 rounded mb-2" style="max-width: 100px;">
                                <p class="text-muted" style="font-size: 0.75rem;">เพื่อเพิ่มเพื่อน</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
}

async function updateAccountOrderBadge() {
    try {
        const updateRes = await fetch('api/client.php?action=check_updates');
        const updateData = await updateRes.json();
        if (updateData.status === 'success') {
            // แสดงเฉพาะตัวเลขจำนวนออเดอร์ที่ยังไม่เสร็จสิ้น (active_orders)
            const activeOrders = parseInt(updateData.active_orders, 10) || 0;
            const navBtns = document.querySelectorAll('.navbar-nav .auth-nav-item button');
            
            navBtns.forEach(btn => {
                const existingBadge = btn.querySelector('.account-order-badge');
                if (existingBadge) existingBadge.remove();
                
                if (activeOrders > 0) {
                    btn.classList.add('position-relative');
                    btn.insertAdjacentHTML('beforeend', ` <span class="account-order-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light shadow-sm" style="font-size: 0.7rem; padding: 0.35em 0.65em;">${activeOrders}<span class="visually-hidden">ออเดอร์ที่ยังไม่เสร็จสิ้น</span></span>`);
                }
            });

            // ปรับตัวเลขในเมนูดรอปดาวน์ "ประวัติการสั่งซื้อ"
            const orderLinks = document.querySelectorAll('.dropdown-menu a[href="profile.html#orders"]');
            orderLinks.forEach(link => {
                const existingBadge = link.querySelector('.account-order-link-badge');
                if (existingBadge) existingBadge.remove();
                if (activeOrders > 0) {
                    link.insertAdjacentHTML('beforeend', ` <span class="account-order-link-badge badge bg-danger ms-1">${activeOrders}</span>`);
                }
            });
        }
    } catch (e) {
        console.error("Failed to check active orders", e);
    }
}

async function checkAuthAndUpdateUI() {
    try {
        const response = await fetch('api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_session' })
        });
        const result = await response.json();
        
        if (result.logged_in) {
            // Update Navigation Bar Login Button to Dropdown
            const navItems = document.querySelectorAll('.navbar-nav .nav-item');
            navItems.forEach(item => {
                const isAuthNode = item.querySelector('a[href="login.html"]') || 
                                   item.querySelector('[data-bs-toggle="dropdown"]') || 
                                   item.innerHTML.includes('ออกจากระบบ');
                
                if (isAuthNode) {
                    const displayName = result.fullname || result.username || 'บัญชีผู้ใช้';
                    const uniqueId = 'userDropdown_' + Math.random().toString(36).substr(2, 9);
                    
                    item.className = "nav-item dropdown ms-2 auth-nav-item";
                    item.innerHTML = `
                        <button class="btn btn-primary-custom btn-sm shadow-sm dropdown-toggle d-flex align-items-center" type="button" id="${uniqueId}" data-bs-toggle="dropdown" aria-expanded="false" style="border: none;">
                            <i class="fas fa-user-circle fs-5 me-1"></i> 
                            <span class="fw-bold">${displayName}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="${uniqueId}">
                            <li><a class="dropdown-item py-2" href="profile.html"><i class="fas fa-user me-2 text-primary-custom"></i>ข้อมูลส่วนตัว</a></li>
                            <li><a class="dropdown-item py-2" href="profile.html#orders"><i class="fas fa-box-open me-2 text-primary-custom"></i>ประวัติการสั่งซื้อ</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="#" onclick="if(confirm('ยืนยันการออกจากระบบหรือไม่?')) window.location.href='api/auth.php?action=logout'; return false;"><i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ</a></li>
                        </ul>
                    `;
                    
                    // Manually initialize the Bootstrap dropdown to ensure it works when dynamically injected
                    const dropdownElement = item.querySelector(`#${uniqueId}`);
                    if (dropdownElement && typeof bootstrap !== 'undefined') {
                        new bootstrap.Dropdown(dropdownElement);
                    }
                }
            });

            // Fetch updates (ตัวเลขจำนวนออเดอร์ที่ยังไม่เสร็จสิ้น)
            await updateAccountOrderBadge();

            // Hide "เข้าสู่ระบบ / สมัครสมาชิก" button on Hero section in index.html
            const heroLoginBtns = document.querySelectorAll('.hero-section a[href="login.html"]');
            heroLoginBtns.forEach(btn => {
                btn.style.display = 'none';
            });
            
            // Show Auth-Restricted Content (e.g., in cart.html)
            document.querySelectorAll('.auth-restricted-content').forEach(el => el.style.display = 'block');
            document.querySelectorAll('.auth-restricted-warning').forEach(el => el.style.display = 'none');

            // Auto-fill cart details if they exist on the page
            const rName = document.getElementById('receiverName');
            const rPhone = document.getElementById('receiverPhone');
            const rAddress = document.getElementById('shippingAddressDetail');
            
            if (rName && result.fullname && !localStorage.getItem('cart_form_receiverName')) {
                rName.value = result.fullname;
            }
            if (rPhone && result.phone && !localStorage.getItem('cart_form_receiverPhone')) {
                rPhone.value = result.phone;
            }
            if (rAddress && result.shipping_address && !localStorage.getItem('cart_form_shippingAddressDetail')) {
                rAddress.value = result.shipping_address;
            }
        } else {
            // Reset Navigation Bar Login Button if it was changed
            const navItems = document.querySelectorAll('.navbar-nav .nav-item');
            navItems.forEach(item => {
                const isAuthNode = item.classList.contains('auth-nav-item') || 
                                   item.querySelector('[data-bs-toggle="dropdown"]') || 
                                   item.querySelector('a[href="login.html"]') || 
                                   item.innerHTML.includes('ออกจากระบบ');
                                   
                if (isAuthNode) {
                    let currentPage = window.location.pathname.split('/').pop() + window.location.search;
                    if (!currentPage || currentPage === window.location.search) currentPage = 'index.html';
                    
                    item.className = "nav-item ms-2 auth-nav-item";
                    item.innerHTML = `<a href="login.html?redirect=${encodeURIComponent(currentPage)}" class="btn btn-primary-custom btn-sm shadow-sm"><i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ</a>`;
                }
            });

            // Show "เข้าสู่ระบบ / สมัครสมาชิก" button on Hero section in index.html if it was hidden
            const heroLoginBtns = document.querySelectorAll('.hero-section a[href="login.html"]');
            heroLoginBtns.forEach(btn => {
                btn.style.display = 'inline-block';
            });

            // Hide Auth-Restricted Content (e.g., in cart.html)
            document.querySelectorAll('.auth-restricted-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.auth-restricted-warning').forEach(el => el.style.display = 'block');
        }
    } catch(e) {
        console.error('Auth check failed', e);
    }
}

// Force localhost to prevent localStorage domain isolation issues with LINE login
// and migrate any existing cart data from 127.0.0.1 to localhost
if (window.location.hostname === '127.0.0.1') {
    let cart = localStorage.getItem('jaonuea_cart');
    let url = new URL(window.location.href.replace('127.0.0.1', 'localhost'));
    if (cart && cart !== '[]') {
        url.searchParams.set('sync_cart', encodeURIComponent(cart));
    }
    window.location.href = url.toString();
} else if (window.location.hostname === 'localhost') {
    let urlParams = new URLSearchParams(window.location.search);
    let syncCart = urlParams.get('sync_cart');
    if (syncCart) {
        let currentCart = localStorage.getItem('jaonuea_cart');
        if (!currentCart || currentCart === '[]') {
            localStorage.setItem('jaonuea_cart', decodeURIComponent(syncCart));
        }
        urlParams.delete('sync_cart');
        let newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, document.title, newUrl);
        // Force update UI if functions are available
        setTimeout(() => {
            if (typeof renderCart === 'function') renderCart();
            if (typeof updateCartBadge === 'function') updateCartBadge();
        }, 100);
    }
}

// Format numbers as currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB' }).format(amount);
}

// --- Shopping Cart Logic ---

function getCartType() {
    return localStorage.getItem('jaonuea_cart_type') || 'retail';
}

function setCartType(type) {
    localStorage.setItem('jaonuea_cart_type', type);
}

function getDeliveryDate() {
    return localStorage.getItem('jaonuea_delivery_date') || '';
}

function setDeliveryDate(date) {
    localStorage.setItem('jaonuea_delivery_date', date);
}

// Get cart from localStorage
function getCart() {
    try {
        let cart = JSON.parse(localStorage.getItem('jaonuea_cart'));
        if (!Array.isArray(cart)) cart = [];
        let validCart = cart.filter(item => item && item.id !== undefined && item.id !== null);
        if (validCart.length !== cart.length) {
            localStorage.setItem('jaonuea_cart', JSON.stringify(validCart));
        }
        return validCart;
    } catch (e) {
        localStorage.removeItem('jaonuea_cart');
        return [];
    }
}

// Save cart to localStorage
function saveCart(cart) {
    localStorage.setItem('jaonuea_cart', JSON.stringify(cart));
    updateCartBadge();
}

// Add retail item to cart
function addToCart(item) {
    let cartType = getCartType();
    let cart = getCart();

    if (cart.length > 0 && cartType !== 'retail') {
        if (!confirm('คุณมีตะกร้าแบบ "ขายส่ง" อยู่ หากต้องการเพิ่มสินค้าปลีก ตะกร้าเดิมจะถูกล้าง ยืนยันหรือไม่?')) {
            return;
        }
        cart = [];
    }

    let existingItem = cart.find(i => i.id == item.id);
    let qty = parseInt(item.quantity);
    if (isNaN(qty) || qty < 1) qty = 1;

    let stockLimit = parseInt(item.stock) || 999;
    let currentQtyInCart = existingItem ? existingItem.quantity : 0;
    
    if (currentQtyInCart + qty > stockLimit) {
        alert('ไม่สามารถเพิ่มสินค้าได้ สต๊อกคงเหลือไม่เพียงพอ (มีเพียง ' + stockLimit + ' กก.)');
        return;
    }

    if (existingItem) {
        existingItem.quantity = parseInt(existingItem.quantity) + qty;
    } else {
        item.quantity = qty;
        cart.push(item);
    }
    
    setCartType('retail');
    setDeliveryDate('');
    saveCart(cart);
    
    alert('เพิ่ม ' + item.name + ' ลงในตะกร้าเรียบร้อยแล้ว');
}

// Set entire cart (for wholesale submit)
function setWholesaleCart(items, deliveryDate) {
    let cart = getCart();
    let cartType = getCartType();
    
    if (cart.length > 0 && cartType !== 'wholesale') {
        if (!confirm('คุณมีตะกร้าแบบ "ปลีก" อยู่ หากต้องการสั่งซื้อแบบขายส่ง ตะกร้าปลีกจะถูกล้าง ยืนยันหรือไม่?')) {
            return false;
        }
    }
    
    setCartType('wholesale');
    setDeliveryDate(deliveryDate);
    saveCart(items);
    alert('นำสินค้าขายส่งลงตะกร้าเรียบร้อยแล้ว');
    window.location.href = 'cart.html';
    return true;
}

// Remove item from cart
function removeFromCart(id) {
    let cart = getCart();
    cart = cart.filter(item => item.id != id);
    saveCart(cart);
    
    // If cart is empty, reset type
    if (cart.length === 0) {
        setCartType('retail');
        setDeliveryDate('');
    }
}

// Update item quantity
function updateQuantity(id, quantity) {
    let cart = getCart();
    let item = cart.find(cartItem => cartItem.id == id);
    if (item) {
        item.quantity = parseFloat(quantity);
        if (item.quantity <= 0) {
            removeFromCart(id);
        } else {
            saveCart(cart);
        }
    }
}

// Update cart badge in header (Image fe35c230.jpg)
function updateCartBadge() {
    let cart = getCart();
    let totalItems = 0;
    if (Array.isArray(cart)) {
        cart.forEach(item => {
            totalItems += parseFloat(item.quantity) || 0;
        });
    }
    
    // Find all cart badge elements across the page
    let cartBadges = document.querySelectorAll('#cart-count, .nav-cart-badge');
    if (cartBadges.length > 0) {
        cartBadges.forEach(badge => {
            if (totalItems > 0) {
                let displayVal = (totalItems % 1 === 0) ? totalItems : parseFloat(totalItems.toFixed(2));
                badge.innerText = displayVal;
                badge.setAttribute('data-count', displayVal);
                badge.classList.remove('d-none');
                badge.style.removeProperty('display');
                badge.style.display = 'inline-flex';
                if (String(displayVal).length >= 3) {
                    badge.classList.add('pill-expanded');
                } else {
                    badge.classList.remove('pill-expanded');
                }
            } else {
                badge.innerText = '';
                badge.setAttribute('data-count', '0');
                badge.classList.add('d-none');
                badge.classList.remove('pill-expanded');
                badge.style.setProperty('display', 'none', 'important');
            }
        });
    } else {
        let badgeFallback = document.querySelector('.fa-shopping-cart')?.closest('a')?.querySelector('.badge');
        if (badgeFallback) {
            if (totalItems > 0) {
                let displayVal = (totalItems % 1 === 0) ? totalItems : parseFloat(totalItems.toFixed(2));
                badgeFallback.innerText = displayVal;
                badgeFallback.setAttribute('data-count', displayVal);
                badgeFallback.classList.remove('d-none');
                badgeFallback.style.removeProperty('display');
                badgeFallback.style.display = 'inline-flex';
            } else {
                badgeFallback.innerText = '';
                badgeFallback.setAttribute('data-count', '0');
                badgeFallback.classList.add('d-none');
                badgeFallback.style.setProperty('display', 'none', 'important');
            }
        }
    }
}

// Sync cart badge across browser tabs/windows automatically
window.addEventListener('storage', function(e) {
    if (e.key === 'jaonuea_cart') {
        updateCartBadge();
    }
});

// Automatically ensure the correct nav-link is marked as active based on current URL
function syncActiveNavLink() {
    try {
        const path = window.location.pathname.toLowerCase();
        const navLinks = document.querySelectorAll('.navbar-custom .navbar-nav .nav-link:not(.dropdown-item)');
        
        let targetHref = '';
        if (path.endsWith('cart.html')) {
            targetHref = 'cart.html';
        } else if (path.endsWith('shop.php')) {
            targetHref = 'shop.php';
        } else if (path.endsWith('profile.html')) {
            targetHref = 'profile.html';
        } else if (path.endsWith('index.html') || path.endsWith('/') || path.endsWith('/jaonuea') || path.endsWith('/jaonuea/')) {
            targetHref = 'index.html';
        }

        if (targetHref) {
            navLinks.forEach(link => {
                const href = (link.getAttribute('href') || '').toLowerCase();
                if (href.includes(targetHref)) {
                    link.classList.add('active');
                } else if (!link.classList.contains('dropdown-toggle')) {
                    link.classList.remove('active');
                }
            });
        }
    } catch (e) {
        console.error('Error syncing active nav link:', e);
    }
}

// Immediate execution if DOM is already parsed
if (document.readyState === 'interactive' || document.readyState === 'complete') {
    updateCartBadge();
    syncActiveNavLink();
} else {
    document.addEventListener('DOMContentLoaded', function() {
        updateCartBadge();
        syncActiveNavLink();
    });
}
