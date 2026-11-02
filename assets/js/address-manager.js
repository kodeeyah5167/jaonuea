/**
 * address-manager.js - ระบบจัดการที่อยู่จัดส่งหลายรายการ (Multi-Address Management)
 * รองรับ Flow ตามตัวอย่าง Shopee (111.jpg, 222.jpg, 333.jpg)
 */

const AddressManager = (function ($) {
    'use strict';

    // State
    let addresses = [];
    let selectedAddress = null;
    let editingAddressId = null;
    let selectedLabel = 'home';
    let onChangeCallback = null;
    let isLoggedIn = false;

    // รายชื่อ 77 จังหวัดในประเทศไทย
    const thaiProvinces = [
        "กรุงเทพมหานคร", "กระบี่", "กาญจนบุรี", "กาฬสินธุ์", "กำแพงเพชร", "ขอนแก่น", "จันทบุรี", "ฉะเชิงเทรา", "ชลบุรี", "ชัยนาท",
        "ชัยภูมิ", "ชุมพร", "เชียงราย", "เชียงใหม่", "ตรัง", "ตราด", "ตาก", "นครนายก", "นครปฐม", "นครพนม",
        "นครราชสีมา", "นครศรีธรรมราช", "นครสวรรค์", "นนทบุรี", "นราธิวาส", "น่าน", "บึงกาฬ", "บุรีรัมย์", "ปทุมธานี", "ประจวบคีรีขันธ์",
        "ปราจีนบุรี", "ปัตตานี", "พระนครศรีอยุธยา", "พะเยา", "พังงา", "พัทลุง", "พิจิตร", "พิษณุโลก", "เพชรบุรี", "เพชรบูรณ์",
        "แพร่", "ภูเก็ต", "มหาสารคาม", "มุกดาหาร", "แม่ฮ่องสอน", "ยโสธร", "ยะลา", "ร้อยเอ็ด", "ระนอง", "ระยอง",
        "ราชบุรี", "ลพบุรี", "ลำปาง", "ลำพูน", "เลย", "ศรีสะเกษ", "สกลนคร", "สงขลา", "สตูล", "สมุทรปราการ",
        "สมุทรสงคราม", "สมุทรสาคร", "สระแก้ว", "สระบุรี", "สิงห์บุรี", "สุโขทัย", "สุพรรณบุรี", "สุราษฎร์ธานี", "สุรินทร์", "หนองคาย",
        "หนองบัวลำภู", "อ่างทอง", "อำนาจเจริญ", "อุดรธานี", "อุตรดิตถ์", "อุทัยธานี", "อุบลราชธานี"
    ];

    // ฟังก์ชันสร้างโครงสร้าง HTML Modals ในหน้าเว็บอัตโนมัติ
    function injectModals() {
        if ($('#addressSelectionModal').length > 0) return;

        const html = `
        <!-- Modal 1: เลือกที่อยู่ (222.jpg) -->
        <div class="modal fade address-modal" id="addressSelectionModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 480px;">
                <div class="modal-content">
                    <div class="address-modal-header">
                        <button type="button" class="btn-back" data-bs-dismiss="modal">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h5 class="modal-title">เลือกที่อยู่</h5>
                    </div>
                    <div class="address-section-label">ที่อยู่</div>
                    <div class="modal-body p-0" id="addressListContainer" style="max-height: 480px; overflow-y: auto;">
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-spinner fa-spin fa-2x mb-2"></i>
                            <div>กำลังโหลดที่อยู่...</div>
                        </div>
                    </div>
                    <div class="address-footer-actions">
                        <button type="button" class="btn-add-new-address" id="btnOpenAddAddress">
                            <i class="fas fa-plus"></i> เพิ่มที่อยู่ใหม่
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal 2: เพิ่ม / แก้ไขที่อยู่ (333.jpg) -->
        <div class="modal fade address-modal" id="addressFormModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 480px;">
                <div class="modal-content">
                    <div class="address-modal-header">
                        <button type="button" class="btn-back" id="btnBackToSelection">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h5 class="modal-title" id="formModalTitle">ที่อยู่ใหม่</h5>
                    </div>
                    <div class="modal-body p-0" style="max-height: 520px; overflow-y: auto;">
                        <!-- ส่วนที่ 1: แปะข้อมูลที่อยู่ (Smart Paste) -->
                        <div class="smart-paste-box">
                            <div class="smart-paste-title">
                                <i class="fas fa-magic"></i> แปะข้อมูลที่อยู่
                            </div>
                            <div class="smart-paste-desc">
                                แปะข้อมูลที่อยู่ของคุณในช่องนี้ เพื่อกรอกข้อมูลโดยอัตโนมัติ
                            </div>
                            <textarea id="smartPasteInput" class="smart-paste-textarea" 
                                placeholder="แปะข้อมูลที่อยู่ของคุณในช่องนี้ จากนั้นกดปุ่มกรอกข้อมูลเพื่อเพิ่มชื่อ เบอร์โทรศัพท์ และที่อยู่ของคุณในช่องโดยอัตโนมัติ"></textarea>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn-smart-parse" id="btnRunSmartPaste">
                                    <i class="fas fa-wand-magic-sparkles"></i> แยกข้อมูลอัตโนมัติ
                                </button>
                            </div>
                        </div>

                        <!-- ส่วนที่ 2: ฟอร์มกรอกที่อยู่ -->
                        <div class="address-form-card">
                            <div class="address-form-card-title">ที่อยู่</div>
                            
                            <div class="addr-field-group">
                                <input type="text" id="inputRecipientName" class="addr-input-underline" placeholder="ชื่อ นามสกุล" required autocomplete="name">
                            </div>

                            <div class="addr-field-group">
                                <input type="tel" id="inputPhone" class="addr-input-underline" placeholder="หมายเลขโทรศัพท์" required autocomplete="tel">
                            </div>

                            <div class="addr-field-group">
                                <button type="button" class="addr-picker-btn empty" id="btnOpenLocationPicker">
                                    <span id="locationPickerDisplay">จังหวัด, เขต/อำเภอ, แขวง/ตำบล, รหัสไปรษณีย์</span>
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                                <input type="hidden" id="inputProvince">
                                <input type="hidden" id="inputDistrict">
                                <input type="hidden" id="inputSubdistrict">
                                <input type="hidden" id="inputZipcode">
                            </div>

                            <div class="addr-field-group">
                                <textarea id="inputAddressDetail" class="addr-input-underline" rows="2" 
                                    placeholder="บ้านเลขที่,ซอย,หมู่,ถนน,แขวง/ตำบล" required></textarea>
                            </div>
                        </div>

                        <!-- ส่วนที่ 3: การตั้งค่าที่อยู่ -->
                        <div class="address-settings-card">
                            <div class="setting-row">
                                <span class="setting-label">เลือกเป็นที่อยู่ตั้งต้น</span>
                                <label class="ios-switch">
                                    <input type="checkbox" id="inputIsDefault">
                                    <span class="ios-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="address-footer-actions">
                        <button type="button" class="btn-confirm-address" id="btnSaveAddress">ยืนยัน</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal 3: Location Cascading Picker (เลือกจังหวัด/อำเภอ/ตำบล/รหัสไปรษณีย์) -->
        <div class="modal fade cascading-picker-modal" id="locationPickerModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-1">
                        <h6 class="modal-title fw-bold" id="pickerStepTitle">เลือกจังหวัด</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body pt-1">
                        <div class="picker-breadcrumbs" id="pickerBreadcrumbs"></div>
                        <input type="text" class="picker-search-input" id="pickerSearch" placeholder="พิมพ์เพื่อค้นหา...">
                        <div class="picker-list" id="pickerListItems"></div>
                    </div>
                </div>
            </div>
        </div>
        `;

        $('body').append(html);
        bindEvents();
    }

    // ฟังก์ชัน Smart Paste: แยกชื่อ เบอร์โทร และที่อยู่อัตโนมัติด้วย Regular Expressions
    function parseSmartAddress(rawText) {
        if (!rawText || !rawText.trim()) return null;

        let text = rawText.trim();
        let extracted = {
            name: '',
            phone: '',
            province: '',
            district: '',
            subdistrict: '',
            zipcode: '',
            detail: ''
        };

        // 1. ดึงเบอร์โทรศัพท์ (08x-xxx-xxxx, 09xxxxxxxx, (+66) xx xxx xxxx ฯลฯ)
        const phoneRegex = /(?:\+66|0)[0-9]{1,2}[-\s]?[0-9]{3}[-\s]?[0-9]{4}/;
        const phoneMatch = text.match(phoneRegex);
        if (phoneMatch) {
            extracted.phone = phoneMatch[0].replace(/[^0-9]/g, '');
            if (extracted.phone.startsWith('66')) {
                extracted.phone = '0' + extracted.phone.substring(2);
            }
            // ลบเบอร์โทรออกจาก text เพื่อไม่ให้ซ้ำ
            text = text.replace(phoneMatch[0], ' ');
        }

        // 2. ดึงรหัสไปรษณีย์ (ตัวเลข 5 หลัก 10000-99999)
        const zipRegex = /\b([1-9][0-9]{4})\b/;
        const zipMatch = text.match(zipRegex);
        if (zipMatch) {
            extracted.zipcode = zipMatch[1];
            text = text.replace(zipMatch[0], ' ');
        }

        // 3. ดึงจังหวัด (ตรวจสอบจากรายชื่อ 77 จังหวัด และคำย่อ เช่น กทม)
        if (text.includes("กทม.") || text.includes("กทม") || text.includes("กรุงเทพฯ") || text.includes("กรุงเทพ")) {
            extracted.province = "กรุงเทพมหานคร";
            text = text.replace(/จ\.\s*กรุงเทพ[มหานคร]*|กรุงเทพมหานคร|กรุงเทพฯ|กทม\.?|จังหวัดกรุงเทพมหานคร/g, ' ');
        } else {
            for (let prov of thaiProvinces) {
                const provRegex = new RegExp('(?:จ\\.|จังหวัด)?\\s*' + prov, 'g');
                if (provRegex.test(text)) {
                    extracted.province = prov;
                    text = text.replace(provRegex, ' ');
                    break;
                }
            }
        }

        // 4. ดึงอำเภอ / เขต
        const districtRegex = /(?:อำเภอ|อ\.|เขต)\s*([ก-๙a-zA-Z0-9]+)/;
        const distMatch = text.match(districtRegex);
        if (distMatch) {
            extracted.district = distMatch[1].trim();
            text = text.replace(distMatch[0], ' ');
        }

        // 5. ดึงตำบล / แขวง
        const subdistrictRegex = /(?:ตำบล|ต\.|แขวง)\s*([ก-๙a-zA-Z0-9]+)/;
        const subMatch = text.match(subdistrictRegex);
        if (subMatch) {
            extracted.subdistrict = subMatch[1].trim();
            text = text.replace(subMatch[0], ' ');
        }

        // 6. แยกชื่อผู้รับและที่อยู่ส่วนที่เหลือ (บ้านเลขที่ ซอย ถนน ฯลฯ)
        // แยกตามบรรทัด หรือคำคั่น
        let lines = text.split(/[\r\n]+/).map(l => l.trim()).filter(l => l.length > 0);
        if (lines.length > 1) {
            // บรรทัดแรกมักเป็นชื่อ
            let potentialName = lines[0].replace(/^(ผู้รับ|ชื่อ|คุณ|name)\s*[:\s]*/i, '').trim();
            // ตรวจสอบว่าบรรทัดแรกไม่ใช่บ้านเลขที่
            if (!/^[0-9]+[\/-]?[0-9]*/.test(potentialName) && potentialName.length < 50) {
                extracted.name = potentialName;
                lines.shift();
            }
            extracted.detail = lines.join(' ').trim();
        } else {
            // ถ้าเป็นบรรทัดเดียว: หาคำว่า ชื่อ / ผู้รับ
            let nameMatch = text.match(/(?:ผู้รับ|ชื่อ)\s*[:\s]*([ก-๙a-zA-Z\s]+?)(?=\s+(?:โทร|เบอร์|ที่อยู่|\d))/i);
            if (nameMatch) {
                extracted.name = nameMatch[1].trim();
                text = text.replace(nameMatch[0], ' ');
            } else {
                // ตัดคำแรกๆ ที่เป็นตัวอักษรไม่ขึ้นต้นด้วยตัวเลข
                let words = text.split(/\s+/).filter(w => w.length > 0);
                if (words.length > 1 && !/^[0-9]/.test(words[0]) && words[0].length > 1) {
                    if (words.length > 2 && !/^[0-9]/.test(words[1])) {
                        extracted.name = words[0] + ' ' + words[1];
                        words.splice(0, 2);
                    } else {
                        extracted.name = words[0];
                        words.splice(0, 1);
                    }
                    text = words.join(' ');
                }
            }
            extracted.detail = text.trim();
        }

        // ทำความสะอาดที่อยู่บ้านเลขที่
        extracted.detail = extracted.detail
            .replace(/^(ที่อยู่|address)\s*[:\s]*/i, '')
            .replace(/\s+/g, ' ')
            .replace(/[,|]/g, ' ')
            .trim();

        return extracted;
    }

    // ฟังก์ชันจัดการเหตุการณ์ (Event Listeners)
    function bindEvents() {
        // เมื่อคลิกเปิดหน้าเลือกที่อยู่จากหน้า Checkout
        $(document).on('click', '.checkout-address-card', function () {
            openAddressSelection();
        });

        // ปุ่มเพิ่มที่อยู่ใหม่ (222.jpg -> 333.jpg)
        $('#btnOpenAddAddress').on('click', function () {
            openAddAddressModal();
        });

        // ปุ่มย้อนกลับจากแบบฟอร์ม กลับไปยังหน้าเลือกที่อยู่
        $('#btnBackToSelection').on('click', function () {
            $('#addressFormModal').modal('hide');
            $('#addressSelectionModal').modal('show');
        });

        // ปุ่ม Smart Paste (แยกข้อมูลอัตโนมัติ)
        $('#btnRunSmartPaste').on('click', function () {
            const raw = $('#smartPasteInput').val();
            if (!raw || !raw.trim()) {
                alert('กรุณาแปะข้อความที่อยู่ลงในช่องก่อนกดแยกข้อมูล');
                return;
            }
            const res = parseSmartAddress(raw);
            if (res) {
                if (res.name) $('#inputRecipientName').val(res.name);
                if (res.phone) $('#inputPhone').val(res.phone);
                if (res.province) $('#inputProvince').val(res.province);
                if (res.district) $('#inputDistrict').val(res.district);
                if (res.subdistrict) $('#inputSubdistrict').val(res.subdistrict);
                if (res.zipcode) $('#inputZipcode').val(res.zipcode);
                if (res.detail) $('#inputAddressDetail').val(res.detail);

                updateLocationDisplay();
                
                // เอฟเฟกต์สีเขียวเล็กน้อยเพื่อบอกว่าแยกสำเร็จ
                $('#btnRunSmartPaste').html('<i class="fas fa-check"></i> แยกข้อมูลสำเร็จ!').css('background-color', '#28a745');
                setTimeout(() => {
                    $('#btnRunSmartPaste').html('<i class="fas fa-wand-magic-sparkles"></i> แยกข้อมูลอัตโนมัติ').css('background-color', 'var(--shopee-orange)');
                }, 1500);
            }
        });

        // ปุ่มเลือกป้ายกำกับ [ที่ทำงาน] [บ้าน]
        $(document).on('click', '.btn-tag-label', function () {
            $('.btn-tag-label').removeClass('active');
            $(this).addClass('active');
            selectedLabel = $(this).data('label');
        });

        // ปุ่มคลิกเลือกรายการที่อยู่ (Radio click หรือ Card click)
        $(document).on('click', '.address-item-card', function (e) {
            // ถ้าคลิกโดนปุ่มแก้ไข ปุ่มลบ หรือปุ่มตั้งเป็นค่าเริ่มต้น ไม่ต้องปิด Modal ทันที
            if ($(e.target).closest('.btn-edit-addr, .btn-delete-addr, .btn-set-default-addr').length > 0) return;

            const addrId = $(this).data('id');
            selectAddressById(addrId, true);
            $('#addressSelectionModal').modal('hide');
        });

        // ปุ่มคลิกตั้งเป็นที่อยู่หลัก
        $(document).on('click', '.btn-set-default-addr', function (e) {
            e.stopPropagation();
            const addrId = $(this).data('id');
            setDefaultAddress(addrId);
        });

        // ปุ่มคลิกแก้ไขที่อยู่
        $(document).on('click', '.btn-edit-addr', function (e) {
            e.stopPropagation();
            const addrId = $(this).data('id');
            openEditAddressModal(addrId);
        });

        // ปุ่มคลิกลบที่อยู่
        $(document).on('click', '.btn-delete-addr', function (e) {
            e.stopPropagation();
            const addrId = $(this).data('id');
            deleteAddressById(addrId);
        });

        // บันทึกที่อยู่ (Add or Edit)
        $('#btnSaveAddress').on('click', function () {
            saveAddressForm();
        });

        // ตัวเลือกจังหวัด/อำเภอ/ตำบล (Location Picker)
        $('#btnOpenLocationPicker').on('click', function () {
            openLocationPicker();
        });
    }

    // โหลดที่อยู่จาก API
    function loadAddresses(callback) {
        $.ajax({
            url: 'api/address.php?action=list',
            type: 'GET',
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    isLoggedIn = (res.logged_in !== false);
                    
                    // กรองข้อมูลตัวอย่าง (Mock Data) ที่อาจตกค้างออก เพื่อความปลอดภัยขั้นสูงสุด
                    addresses = (res.addresses || []).filter(item => {
                        const name = item.recipient_name || '';
                        const phone = (item.phone || '').replace(/[^0-9]/g, '');
                        const detail = item.address_detail || '';
                        if (name.includes('วรายุทธ์') || phone.includes('982282260')) return false;
                        if (name.includes('กัลย์สุดา') || phone.includes('958860516')) return false;
                        if (name.includes('ร้านค้าป้าลอ') || phone.includes('931503897')) return false;
                        if (name.includes('แม่ตุ๊ก')) return false;
                        if (name.includes('คอดีเยะห์') && (detail.includes('โพรงจระเข้') || detail.includes('1 หมู่') || detail.includes('1 ม.') || phone.includes('657081497'))) return false;
                        return true;
                    });
                    
                    // เลือกที่อยู่เริ่มต้นเฉพาะเมื่อล็อกอินแล้วและมีที่อยู่
                    if (isLoggedIn && addresses.length > 0) {
                        if (!selectedAddress && res.default_address) {
                            selectedAddress = res.default_address;
                        } else if (!selectedAddress) {
                            selectedAddress = addresses[0];
                        }
                    } else {
                        // หากยังไม่ล็อกอิน ให้ที่อยู่จัดส่งเป็นข้อมูลว่าง
                        selectedAddress = null;
                    }

                    renderCheckoutCard();
                    renderAddressList();

                    if (typeof callback === 'function') {
                        callback(addresses, selectedAddress, isLoggedIn);
                    }
                    if (typeof onChangeCallback === 'function') {
                        onChangeCallback(selectedAddress, addresses);
                    }
                }
            },
            error: function () {
                console.error('Failed to load user addresses.');
            }
        });
    }

    // เรนเดอร์กล่องที่อยู่ด้านบนหน้า Checkout (111.jpg)
    function renderCheckoutCard() {
        const $card = $('#checkoutTopAddressCard');
        if ($card.length === 0) return;

        if (selectedAddress) {
            $card.removeClass('empty-state');
            const lines = selectedAddress.full_address.split("\n");
            const line1 = lines[0] || '';
            const line2 = lines.slice(1).join(', ') || '';

            $card.html(`
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-map-marker-alt pin-icon"></i>
                        <div>
                            <div>
                                <span class="recipient-name">${escapeHtml(selectedAddress.recipient_name)}</span>
                                <span class="recipient-phone">${escapeHtml(selectedAddress.phone_formatted || selectedAddress.phone)}</span>
                            </div>
                            <div class="address-text">
                                <div>${escapeHtml(line1)}</div>
                                <div>${escapeHtml(line2)}</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <i class="fas fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            `);
        } else {
            // เมื่อยังไม่ได้ล็อกอิน หรือยังไม่มีที่อยู่จัดส่ง แสดงเป็นกล่องว่างอย่างเหมาะสม
            $card.addClass('empty-state');
            $card.html(`
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 py-1">
                    <div class="d-flex align-items-center">
                        <div class="empty-pin-circle me-3">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.98rem;">ยังไม่มีข้อมูลที่อยู่จัดส่ง</div>
                            <div class="text-muted" style="font-size: 0.83rem;">
                                ${!isLoggedIn ? 'เข้าสู่ระบบเพื่อเลือกที่อยู่เดิมของคุณ หรือกดเพื่อเพิ่มที่อยู่ใหม่' : 'กดปุ่มเพิ่มที่อยู่เพื่อระบุปลายทางจัดส่ง'}
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        ${!isLoggedIn ? `
                            <a href="login.html?redirect=cart.html" class="btn btn-sm btn-outline-danger px-3 py-1 text-nowrap" onclick="event.stopPropagation();">
                                <i class="fas fa-sign-in-alt me-1"></i> เข้าสู่ระบบ
                            </a>
                        ` : ''}
                        <button type="button" class="btn btn-sm btn-danger px-3 py-1 text-nowrap" style="background-color: var(--shopee-orange); border-color: var(--shopee-orange);" onclick="event.stopPropagation(); AddressManager.openAdd();">
                            <i class="fas fa-plus me-1"></i> เพิ่มที่อยู่
                        </button>
                    </div>
                </div>
            `);
        }
    }

    // เรนเดอร์รายการที่อยู่ใน Modal (222.jpg)
    function renderAddressList() {
        const $container = $('#addressListContainer');
        if ($container.length === 0) return;

        if (!isLoggedIn) {
            $container.html(`
                <div class="text-center py-5 px-3">
                    <div class="empty-pin-circle mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.4rem;">
                        <i class="fas fa-user-lock text-muted"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">ยังไม่ได้เข้าสู่ระบบ</h6>
                    <p class="text-muted small mb-3">เข้าสู่ระบบเพื่อดูและเลือกที่อยู่จัดส่งที่คุณบันทึกไว้ในบัญชี</p>
                    <a href="login.html?redirect=cart.html" class="btn btn-danger btn-sm px-4 py-2" style="background-color: var(--shopee-orange); border-color: var(--shopee-orange);">
                        <i class="fas fa-sign-in-alt me-1"></i> เข้าสู่ระบบตอนนี้
                    </a>
                </div>
            `);
            return;
        }

        if (addresses.length === 0) {
            $container.html(`
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-map-marker-alt fa-3x mb-3 text-secondary opacity-50"></i>
                    <p class="mb-0 fw-bold">ยังไม่มีที่อยู่จัดส่งที่บันทึกไว้</p>
                    <small>กดปุ่ม "+ เพิ่มที่อยู่ใหม่" ด้านล่างเพื่อเพิ่มที่อยู่แรกของคุณ</small>
                </div>
            `);
            return;
        }

        let html = '';
        addresses.forEach(item => {
            const isSelected = selectedAddress && selectedAddress.id == item.id;
            const fullLines = (item.full_address || '').split("\n").join(' ');

            html += `
            <div class="address-item-card" data-id="${item.id}">
                <div class="shopee-radio-wrap">
                    <div class="shopee-radio ${isSelected ? 'active' : ''}"></div>
                </div>
                <div class="address-item-content">
                    <div class="item-header">
                        <div>
                            <span class="name">${escapeHtml(item.recipient_name)}</span>
                            <span class="phone">${escapeHtml(item.phone_formatted || item.phone)}</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn-edit-addr" data-id="${item.id}">แก้ไข</button>
                            <button type="button" class="btn-edit-addr text-danger btn-delete-addr" data-id="${item.id}">ลบ</button>
                        </div>
                    </div>
                    <div class="detail">${escapeHtml(fullLines)}</div>
                    <div class="mt-1">
                        ${item.is_default == 1
                            ? '<span class="badge-default-addr">ที่อยู่หลัก (ค่าเริ่มต้น)</span>'
                            : `<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 mt-1 btn-set-default-addr" data-id="${item.id}" style="font-size: 0.75rem;">ตั้งเป็นที่อยู่หลัก</button>`
                        }
                    </div>
                </div>
            </div>
            `;
        });

        $container.html(html);
    }

    // เปิดหน้าเลือกที่อยู่ (222.jpg)
    function openAddressSelection() {
        renderAddressList();
        $('#addressSelectionModal').modal('show');
    }

    // เลือกที่อยู่ตาม ID
    function selectAddressById(id, persistDefault = false) {
        const found = addresses.find(a => a.id == id);
        if (found) {
            selectedAddress = found;
            if (persistDefault && isLoggedIn) {
                setDefaultAddress(id);
                return;
            }
            renderCheckoutCard();
            renderAddressList();

            if (typeof onChangeCallback === 'function') {
                onChangeCallback(selectedAddress, addresses);
            }
        }
    }

    // ตั้งเป็นที่อยู่หลัก (ค่าเริ่มต้น)
    function setDefaultAddress(id, callback) {
        if (!isLoggedIn || !id) return;
        $.ajax({
            url: 'api/address.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'set_default', id: id }),
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    addresses.forEach(a => {
                        a.is_default = (a.id == id) ? 1 : 0;
                    });
                    // เรียงที่อยู่หลักขึ้นบนสุด
                    addresses.sort((a, b) => (b.is_default - a.is_default) || (b.id - a.id));
                    const found = addresses.find(a => a.id == id);
                    if (found) selectedAddress = found;

                    renderCheckoutCard();
                    renderAddressList();
                    if (typeof onChangeCallback === 'function') {
                        onChangeCallback(selectedAddress, addresses);
                    }
                    if (typeof callback === 'function') {
                        callback(true);
                    }
                }
            }
        });
    }

    // ลบที่อยู่
    function deleteAddressById(id, callback) {
        if (!isLoggedIn || !id) return;
        if (!confirm('ยืนยันการลบที่อยู่นี้หรือไม่?')) return;
        $.ajax({
            url: 'api/address.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'delete', id: id }),
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    if (selectedAddress && selectedAddress.id == id) {
                        selectedAddress = null;
                    }
                    loadAddresses(function () {
                        if (typeof callback === 'function') {
                            callback(true);
                        }
                    });
                } else {
                    alert(res.message || 'ไม่สามารถลบที่อยู่ได้');
                }
            }
        });
    }

    // เปิดหน้าเพิ่มที่อยู่ใหม่ (333.jpg)
    function openAddAddressModal() {
        editingAddressId = null;
        $('#formModalTitle').text('ที่อยู่ใหม่');
        $('#smartPasteInput').val('');
        $('#inputRecipientName').val('');
        $('#inputPhone').val('');
        $('#inputProvince').val('');
        $('#inputDistrict').val('');
        $('#inputSubdistrict').val('');
        $('#inputZipcode').val('');
        $('#inputAddressDetail').val('');
        $('#inputIsDefault').prop('checked', addresses.length === 0);
        
        $('.btn-tag-label').removeClass('active');
        $('.btn-tag-label[data-label="home"]').addClass('active');
        selectedLabel = 'home';

        updateLocationDisplay();

        $('#addressSelectionModal').modal('hide');
        $('#addressFormModal').modal('show');
    }

    // เปิดหน้าแก้ไขที่อยู่ (333.jpg)
    function openEditAddressModal(id) {
        const item = addresses.find(a => a.id == id);
        if (!item) return;

        editingAddressId = id;
        $('#formModalTitle').text('แก้ไขที่อยู่');
        $('#smartPasteInput').val('');
        $('#inputRecipientName').val(item.recipient_name);
        $('#inputPhone').val(item.phone);
        $('#inputProvince').val(item.province);
        $('#inputDistrict').val(item.district);
        $('#inputSubdistrict').val(item.subdistrict);
        $('#inputZipcode').val(item.zipcode);
        $('#inputAddressDetail').val(item.address_detail);
        $('#inputIsDefault').prop('checked', item.is_default == 1);

        $('.btn-tag-label').removeClass('active');
        $(`.btn-tag-label[data-label="${item.label || 'home'}"]`).addClass('active');
        selectedLabel = item.label || 'home';

        updateLocationDisplay();

        $('#addressSelectionModal').modal('hide');
        $('#addressFormModal').modal('show');
    }

    // อัปเดตข้อความแสดงในปุ่มเลือกจังหวัด/อำเภอ
    function updateLocationDisplay() {
        const prov = $('#inputProvince').val();
        const dist = $('#inputDistrict').val();
        const sub = $('#inputSubdistrict').val();
        const zip = $('#inputZipcode').val();

        const $btn = $('#btnOpenLocationPicker');
        const $text = $('#locationPickerDisplay');

        if (prov && dist && sub && zip) {
            $text.text(`${prov}, ${dist}, ${sub}, ${zip}`);
            $btn.removeClass('empty');
        } else if (prov) {
            $text.text(`${prov} ...`);
            $btn.removeClass('empty');
        } else {
            $text.text('จังหวัด, เขต/อำเภอ, แขวง/ตำบล, รหัสไปรษณีย์');
            $btn.addClass('empty');
        }
    }

    // บันทึกฟอร์มที่อยู่ (Add/Edit)
    function saveAddressForm() {
        if (!isLoggedIn) {
            alert('กรุณาเข้าสู่ระบบก่อนทำการบันทึกที่อยู่');
            window.location.href = 'login.html?redirect=cart.html';
            return;
        }

        const recipientName = $('#inputRecipientName').val().trim();
        const phone = $('#inputPhone').val().trim();
        const province = $('#inputProvince').val().trim();
        const district = $('#inputDistrict').val().trim();
        const subdistrict = $('#inputSubdistrict').val().trim();
        const zipcode = $('#inputZipcode').val().trim();
        const addressDetail = $('#inputAddressDetail').val().trim();
        const isDefault = $('#inputIsDefault').is(':checked') ? 1 : 0;

        if (!recipientName) {
            alert('กรุณากรอกชื่อ-นามสกุล ผู้รับ');
            $('#inputRecipientName').focus();
            return;
        }
        if (!phone) {
            alert('กรุณากรอกหมายเลขโทรศัพท์');
            $('#inputPhone').focus();
            return;
        }
        if (!province || !district || !subdistrict || !zipcode) {
            alert('กรุณาเลือก จังหวัด, เขต/อำเภอ, แขวง/ตำบล, รหัสไปรษณีย์');
            openLocationPicker();
            return;
        }
        if (!addressDetail) {
            alert('กรุณากรอกรายละเอียดบ้านเลขที่ ซอย ถนน');
            $('#inputAddressDetail').focus();
            return;
        }

        const payload = {
            action: 'save',
            id: editingAddressId,
            recipient_name: recipientName,
            phone: phone,
            province: province,
            district: district,
            subdistrict: subdistrict,
            zipcode: zipcode,
            address_detail: addressDetail,
            label: selectedLabel,
            is_default: isDefault
        };

        $('#btnSaveAddress').prop('disabled', true).text('กำลังบันทึก...');

        $.ajax({
            url: 'api/address.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',
            success: function (res) {
                $('#btnSaveAddress').prop('disabled', false).text('ยืนยัน');
                if (res.success) {
                    $('#addressFormModal').modal('hide');
                    loadAddresses(function () {
                        // เลือกรายการที่เพิ่งบันทึก
                        if (res.id) {
                            selectAddressById(res.id);
                        }
                    });
                } else {
                    alert('เกิดข้อผิดพลาด: ' + (res.message || 'ไม่สามารถบันทึกได้'));
                }
            },
            error: function () {
                $('#btnSaveAddress').prop('disabled', false).text('ยืนยัน');
                alert('การเชื่อมต่อผิดพลาด โปรดลองอีกครั้ง');
            }
        });
    }

    // ตัวเลือกตำแหน่งที่ตั้ง (Cascading Location Picker) - ฐานข้อมูลจังหวัด/เขต-อำเภอ/แขวง-ตำบล ทั้งหมดในไทย
    let pickerStep = 'province';
    let tempLoc = { province: '', district: '', subdistrict: '', zipcode: '' };
    let thaiAddressMap = {};
    let thaiAddressFlat = [];
    let isThaiDbLoaded = false;
    let thaiDbLoadingPromise = null;

    // ฟังก์ชันแตกข้อมูล db.json ของ jquery.Thailand.js ออกเป็นรายการ { province, amphoe, district, zipcode }
    function preprocessThaiDb(data) {
        if (!data) return [];
        // กรณีเป็น raw_database.json (Array ของ Object)
        if (Array.isArray(data)) {
            if (data.length > 0 && data[0].amphure) {
                // กรณีโครงสร้าง kongvut/thai-province-data
                const flat = [];
                data.forEach(prov => {
                    const pName = String(prov.name_th || '').trim();
                    (prov.amphure || []).forEach(amp => {
                        let aName = String(amp.name_th || '').trim().replace(/^เขต/, '').replace(/^อำเภอ/, '').trim();
                        (amp.tambon || []).forEach(tam => {
                            let tName = String(tam.name_th || '').trim().replace(/^แขวง/, '').replace(/^ตำบล/, '').trim();
                            flat.push({
                                province: pName,
                                amphoe: aName,
                                district: tName,
                                zipcode: String(tam.zip_code || '')
                            });
                        });
                    });
                });
                return flat;
            }
            return data;
        }

        // กรณีเป็น db.json แบบบีบอัด (lookup + words + data)
        let lookup = [];
        let words = [];
        let expanded = [];
        let useLookup = false;

        if (data.lookup && data.words) {
            useLookup = true;
            lookup = data.lookup.split('|');
            words = data.words.split('|');
            data = data.data;
        }

        function t(text) {
            function repl(m) {
                const ch = m.charCodeAt(0);
                return words[ch < 97 ? ch - 65 : 26 + ch - 97];
            }
            if (!useLookup) return text;
            if (typeof text === 'number') {
                text = lookup[text];
            }
            return String(text).replace(/[A-Z]/ig, repl);
        }

        if (Array.isArray(data)) {
            data.forEach(function (provinces) {
                let i = (provinces.length === 3) ? 2 : 1;
                (provinces[i] || []).forEach(function (amphoes) {
                    (amphoes[i] || []).forEach(function (districts) {
                        const zips = Array.isArray(districts[i]) ? districts[i] : [districts[i]];
                        zips.forEach(function (zipcode) {
                            expanded.push({
                                district: t(districts[0]),
                                amphoe: t(amphoes[0]),
                                province: t(provinces[0]),
                                zipcode: String(zipcode || '')
                            });
                        });
                    });
                });
            });
        }
        return expanded;
    }

    function buildThaiAddressMap(entries) {
        const map = {};
        thaiAddressFlat = entries || [];

        thaiAddressFlat.forEach(item => {
            const prov = String(item.province || '').trim();
            const dist = String(item.amphoe || '').trim();
            const sub = String(item.district || '').trim();
            const zip = String(item.zipcode || '').trim();
            if (!prov || !dist || !sub) return;

            if (!map[prov]) {
                map[prov] = {};
            }
            if (!map[prov][dist]) {
                map[prov][dist] = [];
            }
            const exists = map[prov][dist].some(s => s.name === sub && s.zipcode === zip);
            if (!exists) {
                map[prov][dist].push({ name: sub, zipcode: zip });
            }
        });

        // เรียงลำดับแขวง/ตำบลตามตัวอักษรภาษาไทย
        Object.keys(map).forEach(prov => {
            Object.keys(map[prov]).forEach(dist => {
                map[prov][dist].sort((a, b) => a.name.localeCompare(b.name, 'th'));
            });
        });

        thaiAddressMap = map;
        isThaiDbLoaded = Object.keys(thaiAddressMap).length > 0;
    }

    function loadThaiAddressDatabase() {
        if (isThaiDbLoaded) {
            return Promise.resolve(thaiAddressMap);
        }
        if (thaiDbLoadingPromise) {
            return thaiDbLoadingPromise;
        }

        const urls = [
            'https://earthchie.github.io/jquery.Thailand.js/jquery.Thailand.js/database/db.json',
            'https://cdn.jsdelivr.net/gh/earthchie/jquery.Thailand.js/jquery.Thailand.js/database/db.json',
            'https://raw.githubusercontent.com/earthchie/jquery.Thailand.js/master/jquery.Thailand.js/database/raw_database/raw_database.json',
            'https://raw.githubusercontent.com/kongvut/thai-province-data/master/api_province_with_amphure_tambon.json'
        ];

        thaiDbLoadingPromise = (async function () {
            for (const url of urls) {
                try {
                    const res = await fetch(url);
                    if (!res.ok) continue;
                    const raw = await res.json();
                    const entries = preprocessThaiDb(raw);
                    if (entries && entries.length > 0) {
                        buildThaiAddressMap(entries);
                        if (isThaiDbLoaded) {
                            return thaiAddressMap;
                        }
                    }
                } catch (err) {
                    console.warn('Fallback Thai address DB fetch failed for:', url, err);
                }
            }
            return thaiAddressMap;
        })();

        return thaiDbLoadingPromise;
    }

    function openLocationPicker() {
        pickerStep = 'province';
        tempLoc = { province: '', district: '', subdistrict: '', zipcode: '' };
        renderPickerStep();
        $('#locationPickerModal').modal('show');
    }

    function renderPickerStep() {
        const $list = $('#pickerListItems');
        const $title = $('#pickerStepTitle');
        const $crumbs = $('#pickerBreadcrumbs');
        $('#pickerSearch').val('');

        if (!isThaiDbLoaded && pickerStep !== 'province') {
            $list.html('<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลดข้อมูลเขต/อำเภอ แขวง/ตำบล...</div>');
            loadThaiAddressDatabase().then(() => {
                renderPickerStep();
            });
            return;
        }

        if (pickerStep === 'province') {
            $title.text('เลือกจังหวัด');
            $crumbs.html('');
            const provList = isThaiDbLoaded
                ? Object.keys(thaiAddressMap).sort((a, b) => a.localeCompare(b, 'th'))
                : thaiProvinces.slice().sort((a, b) => a.localeCompare(b, 'th'));
            renderPickerList(provList);
        } else if (pickerStep === 'district') {
            const isBkk = (tempLoc.province === 'กรุงเทพมหานคร');
            $title.text(`เลือก${isBkk ? 'เขต' : 'อำเภอ'} (${tempLoc.province})`);
            $crumbs.html(`<a href="#" class="text-decoration-none picker-crumb-step" data-step="province"><span class="text-muted">จังหวัด:</span> ${escapeHtml(tempLoc.province)}</a>`);
            const districts = getDistrictsByProvince(tempLoc.province);
            renderPickerList(districts);
        } else if (pickerStep === 'subdistrict') {
            const isBkk = (tempLoc.province === 'กรุงเทพมหานคร');
            $title.text(`เลือก${isBkk ? 'แขวง' : 'ตำบล'} (${tempLoc.district})`);
            $crumbs.html(`
                <a href="#" class="text-decoration-none picker-crumb-step" data-step="province"><span class="text-muted">จังหวัด:</span> ${escapeHtml(tempLoc.province)}</a>
                &gt;
                <a href="#" class="text-decoration-none picker-crumb-step" data-step="district"><span class="text-muted">${isBkk ? 'เขต' : 'อำเภอ'}:</span> ${escapeHtml(tempLoc.district)}</a>
            `);
            const subs = getSubdistrictsByDistrict(tempLoc.province, tempLoc.district);
            renderPickerList(subs);
        }

        $('.picker-crumb-step').off('click').on('click', function (e) {
            e.preventDefault();
            const targetStep = $(this).data('step');
            if (targetStep === 'province') {
                pickerStep = 'province';
                tempLoc = { province: '', district: '', subdistrict: '', zipcode: '' };
                renderPickerStep();
            } else if (targetStep === 'district') {
                pickerStep = 'district';
                tempLoc.district = '';
                tempLoc.subdistrict = '';
                tempLoc.zipcode = '';
                renderPickerStep();
            }
        });
    }

    function renderPickerList(items) {
        const $list = $('#pickerListItems');
        let html = '';
        items.forEach(it => {
            const name = typeof it === 'object' ? it.name : it;
            const extra = typeof it === 'object' ? `<span class="text-muted small">${it.zipcode || ''}</span>` : '<i class="fas fa-chevron-right text-muted small"></i>';
            html += `<div class="picker-list-item" data-val="${escapeHtml(name)}" data-zip="${it.zipcode || ''}">
                <span>${escapeHtml(name)}</span>
                ${extra}
            </div>`;
        });
        $list.html(html);

        // ค้นหา
        $('#pickerSearch').off('input').on('input', function () {
            const q = $(this).val().toLowerCase().trim();
            $('.picker-list-item').each(function () {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(q));
            });
        });

        // คลิกเลือกรายการ
        $('.picker-list-item').off('click').on('click', function () {
            const val = $(this).data('val');
            const zip = $(this).data('zip');

            if (pickerStep === 'province') {
                tempLoc.province = val;
                pickerStep = 'district';
                renderPickerStep();
            } else if (pickerStep === 'district') {
                tempLoc.district = val;
                pickerStep = 'subdistrict';
                renderPickerStep();
            } else if (pickerStep === 'subdistrict') {
                tempLoc.subdistrict = val;
                tempLoc.zipcode = zip || '';
                
                // กรอกลงฟอร์ม
                $('#inputProvince').val(tempLoc.province);
                $('#inputDistrict').val(tempLoc.district);
                $('#inputSubdistrict').val(tempLoc.subdistrict);
                $('#inputZipcode').val(tempLoc.zipcode);
                updateLocationDisplay();

                $('#locationPickerModal').modal('hide');
            }
        });
    }

    function getDistrictsByProvince(province) {
        if (isThaiDbLoaded && thaiAddressMap[province]) {
            return Object.keys(thaiAddressMap[province]).sort((a, b) => a.localeCompare(b, 'th'));
        }
        return [];
    }

    function getSubdistrictsByDistrict(province, district) {
        if (isThaiDbLoaded && thaiAddressMap[province] && thaiAddressMap[province][district]) {
            return thaiAddressMap[province][district];
        }
        return [];
    }

    function escapeHtml(text) {
        if (!text) return '';
        return $('<div>').text(text).html();
    }

    // Public API
    return {
        init: function (options) {
            options = options || {};
            onChangeCallback = options.onChange || null;

            injectModals();
            loadThaiAddressDatabase();
            loadAddresses(function (list, def) {
                if (options.container) {
                    renderCheckoutCard();
                }
            });
        },
        getSelectedAddress: function () {
            return selectedAddress;
        },
        getAddresses: function () {
            return addresses;
        },
        openSelection: function () {
            openAddressSelection();
        },
        openAdd: function () {
            openAddAddressModal();
        },
        openEdit: function (id) {
            openEditAddressModal(id);
        },
        setDefault: function (id, cb) {
            setDefaultAddress(id, cb);
        },
        deleteAddress: function (id, cb) {
            deleteAddressById(id, cb);
        },
        reload: function (cb) {
            loadAddresses(cb);
        }
    };

})(jQuery);

