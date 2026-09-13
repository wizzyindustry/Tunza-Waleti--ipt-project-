// assets/js/profile.js - Tunza Waleti Profile Page Interactive Logic

document.addEventListener('DOMContentLoaded', function () {
    const avatarWrapper = document.getElementById('avatarWrapper');
    const avatarFileInput = document.getElementById('avatarFileInput');
    const profileAvatarPreview = document.getElementById('profileAvatarPreview');
    const headerProfileAvatar = document.getElementById('headerProfileAvatar');

    const fullNameInput = document.getElementById('fullName');
    const usernameInput = document.getElementById('username');
    const displayFullName = document.getElementById('displayFullName');
    const displayUsernameTag = document.getElementById('displayUsernameTag');
    const headerUserName = document.getElementById('headerUserName');

    const profileForm = document.getElementById('profileForm');
    const toastNotification = document.getElementById('toastNotification');
    const toastMessage = document.getElementById('toastMessage');

    const currentPin = document.getElementById('currentPin');
    const newPin = document.getElementById('newPin');
    const confirmPin = document.getElementById('confirmPin');

    // 1. Avatar File Upload & Preview
    if (avatarWrapper && avatarFileInput) {
        avatarWrapper.addEventListener('click', function () {
            avatarFileInput.click();
        });

        avatarFileInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                if (!file.type.startsWith('image/')) {
                    alert('Please select an image file (PNG, JPG, JPEG).');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function (event) {
                    const imageUrl = event.target.result;
                    if (profileAvatarPreview) profileAvatarPreview.src = imageUrl;
                    if (headerProfileAvatar) headerProfileAvatar.src = imageUrl;

                    showToast('Profile photo updated!');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // 2. Real-Time Name & Username Mirroring
    if (fullNameInput && displayFullName) {
        fullNameInput.addEventListener('input', function () {
            const nameVal = this.value.trim() || 'User Name';
            displayFullName.textContent = nameVal;
            if (headerUserName) headerUserName.textContent = nameVal;
        });
    }

    if (usernameInput && displayUsernameTag) {
        usernameInput.addEventListener('input', function () {
            const userVal = this.value.trim() || 'user';
            displayUsernameTag.textContent = '@' + userVal.replace(/^@/, '');
        });
    }

    // 3. Show Toast Notification
    function showToast(message) {
        if (toastNotification && toastMessage) {
            toastMessage.textContent = message;
            toastNotification.style.display = 'flex';
            toastNotification.classList.add('show');

            setTimeout(() => {
                toastNotification.classList.remove('show');
                setTimeout(() => {
                    toastNotification.style.display = 'none';
                }, 300);
            }, 3000);
        } else {
            alert(message);
        }
    }

    // 4. Handle Profile Form Submit
    if (profileForm) {
        profileForm.addEventListener('submit', function (e) {
            e.preventDefault();

            // Validate PIN match if user entered new PIN
            if (newPin && newPin.value) {
                if (newPin.value !== confirmPin.value) {
                    alert('New PIN / Password does not match confirmation.');
                    return;
                }
                if (!currentPin.value) {
                    alert('Please enter your Current PIN / Password to confirm security changes.');
                    return;
                }
            }

            showToast('Account profile and settings saved successfully!');

            // Clear sensitive pin inputs
            if (currentPin) currentPin.value = '';
            if (newPin) newPin.value = '';
            if (confirmPin) confirmPin.value = '';
        });
    }
});
