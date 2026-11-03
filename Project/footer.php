        </div><!-- end content-area -->
    </div><!-- end main-content -->
    
    <!-- Notification Popup Container -->
    <div class="notif-popup" id="notifPopup"></div>

    <!-- Universal Camera Modal (Desktop Webcam + Mobile Camera) -->
    <div class="modal fade" id="universalCameraModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h6 class="modal-title mb-0"><i class="bi bi-camera"></i> เปิดกล้องถ่ายภาพสินค้า</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopCameraStream()"></button>
                </div>
                <div class="modal-body p-0 text-center bg-black position-relative" style="min-height: 280px; overflow: hidden;">
                    <video id="cameraVideo" autoplay playsinline muted style="width: 100%; max-height: 60vh; object-fit: contain; background: #000;"></video>
                    <canvas id="cameraCanvas" style="display: none;"></canvas>
                    <div id="cameraLoading" class="position-absolute top-50 start-50 translate-middle text-white text-center">
                        <div class="spinner-border text-light" role="status"></div>
                        <div class="small mt-2">กำลังเชื่อมต่อกล้อง...</div>
                    </div>
                    <div id="cameraError" class="position-absolute top-50 start-50 translate-middle text-white p-3 w-100 d-none">
                        <i class="bi bi-camera-video-off display-6 text-danger"></i>
                        <p class="mt-2 mb-1" id="cameraErrorMsg">ไม่สามารถเปิดกล้องได้</p>
                        <small class="text-white-50">กรุณาอนุญาตการเข้าถึงกล้อง หรือใช้วิธีเลือกรูปจากเครื่อง/ถ่ายผ่านปุ่มระบบแทน</small>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" id="btnSwitchCamera" onclick="switchCameraLens()" title="สลับกล้องหน้า/หลัง">
                        <i class="bi bi-arrow-repeat"></i> สลับกล้อง
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopCameraStream()">ยกเลิก</button>
                        <button type="button" class="btn btn-primary px-4" id="btnSnapPhoto" onclick="takeCameraSnapshot()">
                            <i class="bi bi-camera-fill"></i> ถ่ายภาพ
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
        
        // Notification popup
        function showNotification(title, message, type = 'info') {
            const popup = document.getElementById('notifPopup');
            const icons = {
                'info': 'bi-info-circle',
                'success': 'bi-check-circle',
                'warning': 'bi-exclamation-triangle',
                'danger': 'bi-x-circle'
            };
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi ${icons[type] || icons['info']} fs-5"></i>
                        <div>
                            <strong>${title}</strong>
                            <p class="mb-0 small">${message}</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            popup.insertAdjacentHTML('beforeend', alertHtml);
            setTimeout(() => {
                const firstAlert = popup.querySelector('.alert');
                if (firstAlert) {
                    firstAlert.classList.remove('show');
                    setTimeout(() => firstAlert.remove(), 300);
                }
            }, 5000);
        }
        
        // Check notifications periodically
        <?php if (isLoggedIn()): ?>
        function checkNotifications() {
            fetch('<?= SITE_URL ?>api/check_notifications.php')
                .then(r => r.json())
                .then(data => {
                    const badges = document.querySelectorAll('.notif-badge');
                    if (data.count > 0) {
                        badges.forEach(b => {
                            b.textContent = data.count;
                            b.classList.remove('d-none');
                        });
                        if (data.new_notifications && data.new_notifications.length > 0) {
                            data.new_notifications.forEach(n => {
                                showNotification(n.title, n.message, n.type === 'low_stock' ? 'warning' : 'info');
                            });
                        }
                    } else {
                        badges.forEach(b => {
                            b.textContent = '0';
                            b.classList.add('d-none');
                        });
                    }
                })
                .catch(() => {});
        }
        setInterval(checkNotifications, 30000);
        <?php endif; ?>
        
        // ===== Camera System (Cross-platform: Desktop Webcam + Mobile) =====
        let currentCameraStream = null;
        let currentFacingMode = 'environment';
        let activeTargetBase64InputId = null;
        let activeTargetPreviewId = null;

        function triggerCamera(base64InputId, previewId, nativeFileInputId) {
            activeTargetBase64InputId = base64InputId;
            activeTargetPreviewId = previewId;

            // Check MediaDevices support
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                const modalEl = document.getElementById('universalCameraModal');
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
                startCameraStream();
            } else if (nativeFileInputId) {
                // Fallback to native mobile camera input
                const nativeInput = document.getElementById(nativeFileInputId);
                if (nativeInput) {
                    nativeInput.setAttribute('capture', 'environment');
                    nativeInput.click();
                }
            } else {
                alert('เบราว์เซอร์หรืออุปกรณ์นี้ยังไม่รองรับการเปิดกล้องโดยตรง กรุณาเลือกไฟล์ภาพจากเครื่อง');
            }
        }

        function startCameraStream() {
            stopCameraStream();
            const video = document.getElementById('cameraVideo');
            const loading = document.getElementById('cameraLoading');
            const errorEl = document.getElementById('cameraError');
            if (loading) loading.classList.remove('d-none');
            if (errorEl) errorEl.classList.add('d-none');

            const constraints = {
                video: {
                    facingMode: currentFacingMode,
                    width: { ideal: 1920 },
                    height: { ideal: 1080 }
                },
                audio: false
            };

            navigator.mediaDevices.getUserMedia(constraints)
                .then(stream => {
                    currentCameraStream = stream;
                    video.srcObject = stream;
                    video.play();
                    if (loading) loading.classList.add('d-none');
                })
                .catch(err => {
                    // Fallback without specific facingMode (for laptops/webcams)
                    navigator.mediaDevices.getUserMedia({ video: true, audio: false })
                        .then(stream => {
                            currentCameraStream = stream;
                            video.srcObject = stream;
                            video.play();
                            if (loading) loading.classList.add('d-none');
                        })
                        .catch(e => {
                            if (loading) loading.classList.add('d-none');
                            if (errorEl) {
                                errorEl.classList.remove('d-none');
                                document.getElementById('cameraErrorMsg').textContent = 'ไม่สามารถเข้าถึงกล้องได้: ' + (e.message || e.name);
                            }
                        });
                });
        }

        function switchCameraLens() {
            currentFacingMode = (currentFacingMode === 'environment') ? 'user' : 'environment';
            startCameraStream();
        }

        function stopCameraStream() {
            if (currentCameraStream) {
                currentCameraStream.getTracks().forEach(track => track.stop());
                currentCameraStream = null;
            }
            const video = document.getElementById('cameraVideo');
            if (video) video.srcObject = null;
        }

        function takeCameraSnapshot() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            if (!video || !canvas || !video.videoWidth) {
                alert('กล้องยังไม่พร้อมใช้งาน กรุณารอสักครู่');
                return;
            }

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            const dataUrl = canvas.toDataURL('image/jpeg', 0.88);

            if (activeTargetBase64InputId) {
                const input = document.getElementById(activeTargetBase64InputId);
                if (input) input.value = dataUrl;
            }
            if (activeTargetPreviewId) {
                const preview = document.getElementById(activeTargetPreviewId);
                if (preview) {
                    preview.src = dataUrl;
                    preview.style.display = 'block';
                    const wrap = preview.closest('.photo-preview-wrap');
                    if (wrap) wrap.classList.remove('d-none');
                }
            }

            stopCameraStream();
            const modalEl = document.getElementById('universalCameraModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        }

        function clearCapturedPhoto(base64InputId, previewId, fileInputId) {
            if (base64InputId) {
                const inp = document.getElementById(base64InputId);
                if (inp) inp.value = '';
            }
            if (fileInputId) {
                const finp = document.getElementById(fileInputId);
                if (finp) finp.value = '';
            }
            if (previewId) {
                const prev = document.getElementById(previewId);
                if (prev) {
                    prev.src = '';
                    prev.style.display = 'none';
                    const wrap = prev.closest('.photo-preview-wrap');
                    if (wrap) wrap.classList.add('d-none');
                }
            }
        }

        // Preview image for file inputs
        function previewImage(input, previewId, base64ClearId) {
            const preview = document.getElementById(previewId);
            if (base64ClearId) {
                const b64 = document.getElementById(base64ClearId);
                if (b64) b64.value = '';
            }
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                        const wrap = preview.closest('.photo-preview-wrap');
                        if (wrap) wrap.classList.remove('d-none');
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Get current location
        function getLocation(latId, lngId, btnId) {
            const btn = document.getElementById(btnId);
            if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังหาตำแหน่ง...';
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        document.getElementById(latId).value = pos.coords.latitude;
                        document.getElementById(lngId).value = pos.coords.longitude;
                        if (btn) btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> บันทึกพิกัดสำเร็จ';
                        btn.classList.remove('btn-outline-primary');
                        btn.classList.add('btn-success');
                        showNotification('ตำแหน่ง', 'บันทึกตำแหน่งสำเร็จ', 'success');
                    },
                    function(err) {
                        if (btn) btn.innerHTML = '<i class="bi bi-geo-alt"></i> ระบุตำแหน่ง';
                        showNotification('ข้อผิดพลาด', 'ไม่สามารถหาตำแหน่งได้: ' + err.message, 'danger');
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            } else {
                showNotification('ข้อผิดพลาด', 'เบราว์เซอร์ไม่รองรับ GPS', 'warning');
            }
        }
        
        // Confirm delete
        function confirmDelete(url, name) {
            if (confirm('ยืนยันการลบ: ' + name + ' ?')) {
                window.location.href = url;
            }
        }
        
        <?php if (isStaff() && $lowStockCount > 0): ?>
        // Show low stock notification on page load
        window.addEventListener('load', function() {
            showNotification(
                'สินค้าใกล้หมด!',
                'มีสินค้า <?= $lowStockCount ?> รายการที่ต้องสั่งเพิ่ม',
                'warning'
            );
        });
        <?php endif; ?>
    </script>
</body>
</html>

