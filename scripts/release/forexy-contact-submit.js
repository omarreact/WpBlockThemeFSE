    enquiryForm.addEventListener('submit', function (event) {
        if (event.submitter?.name === 'pin_send' && enquiryForm.dataset.mglSendEnabled === '1') {
            const email = enquiryForm.elements.namedItem('contact');
            if (!validateEnquiryForm(enquiryForm) || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email?.value.trim() || '')) {
                event.preventDefault();
                if (status) status.textContent = 'Complete the required fields and enter a valid email address to send.';
                (enquiryForm.querySelector('[aria-invalid="true"]') || email)?.focus();
            }
            return;
        }
        event.preventDefault();