function previewImage(event) {
    const input = event.target;
    const previewImg = document.getElementById('previewImg');
    const placeholder = document.getElementById('previewPlaceholder');
    const removeBtn = document.getElementById('removeDesignBtn');

    if (input && input.files && input.files[0]) {
        const file = input.files[0];
        if (!['image/jpeg', 'image/png', 'image/gif'].includes(file.type)) {
            alert("Please select a JPG, PNG, or GIF image of your shirt design.");
            input.value = "";
            return;
        }

        const reader = new FileReader();

        reader.onload = function(e) {
            if (previewImg) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
            }
            if (placeholder) {
                placeholder.style.display = 'none';
            }
            if (removeBtn) {
                removeBtn.style.display = 'inline-flex';
            }
        };

        reader.readAsDataURL(file);
    }
}

function removeDesign() {
    const input = document.getElementById('designInput');
    const previewImg = document.getElementById('previewImg');
    const placeholder = document.getElementById('previewPlaceholder');
    const removeBtn = document.getElementById('removeDesignBtn');

    if (input) input.value = '';
    if (previewImg) {
        previewImg.src = '';
        previewImg.style.display = 'none';
    }
    if (placeholder) {
        placeholder.style.display = 'flex';
    }
    if (removeBtn) {
        removeBtn.style.display = 'none';
    }
}

function updateShirtStyle() {
    const styleSelect = document.getElementById('shirtStyle');
    const shirtBox = document.getElementById('shirtBox');
    
    if (styleSelect && shirtBox) {
        shirtBox.style.transition = 'all 0.3s ease';

        switch (styleSelect.value) {
            case 'Long Sleeve':
                shirtBox.style.borderRadius = '24px';
                break;
            case 'Sleeveless':
                shirtBox.style.borderRadius = '6px';
                break;
            default:
                shirtBox.style.borderRadius = '16px';
                break;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateShirtStyle();

    const styleSelect = document.getElementById('shirtStyle');
    if (styleSelect) {
        styleSelect.addEventListener('change', updateShirtStyle);
    }

    const designInput = document.getElementById('designInput');
    if (designInput) {
        designInput.addEventListener('change', previewImage);
    }

    const removeBtn = document.getElementById('removeDesignBtn');
    if (removeBtn) {
        removeBtn.addEventListener('click', removeDesign);
    }

});
