
// ==========================================
// PROFILE EDIT MODE
// ==========================================

// Switch between profile view and edit mode
function toggleProfileEdit(showEdit) {

    document.getElementById('profile-view').style.display =
        showEdit ? 'none' : 'block';

    document.getElementById('profile-edit').style.display =
        showEdit ? 'block' : 'none';

    document.getElementById('edit-profile-button').style.display =
        showEdit ? 'none' : 'inline-flex';
}


// ==========================================
// PROFILE PICTURE PREVIEW
// ==========================================

const avatarInput = document.getElementById('avatar-input');

if (avatarInput) {

    avatarInput.addEventListener('change', function () {

        const file = this.files[0];

        if (!file) {
            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {
            alert('Please select a JPG, PNG, or WebP image.');
            this.value = '';
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            alert('Profile picture must not exceed 2 MB.');
            this.value = '';
            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {

            const preview = document.getElementById('avatar-preview');

            preview.innerHTML = `
                <img
                    src="${event.target.result}"
                    alt="Profile picture preview">
            `;

        };

        reader.readAsDataURL(file);

    });

}
