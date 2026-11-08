document.querySelectorAll('[data-order-items]').forEach((section) => {
    const container = section.querySelector('[data-order-rows]');
    const template = section.querySelector('[data-order-template]');
    const originalPrices = JSON.parse(section.dataset.existingPrices || '{}');
    let nextIndex = Math.max(-1, ...Array.from(container.querySelectorAll('[data-product]'), (select) => Number(select.name.match(/\[(\d+)\]/)?.[1] ?? -1))) + 1;

    const money = (cents) => `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
    const recalculate = () => {
        let total = 0n;
        container.querySelectorAll('[data-order-row]').forEach((row) => {
            const product = row.querySelector('[data-product]');
            const price = originalPrices[product.value] ?? product.selectedOptions[0]?.dataset.price ?? '0.00';
            const [whole, fraction = ''] = price.split('.');
            const cents = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
            const quantity = row.querySelector('[data-quantity]').value;
            const subtotal = cents * (/^\d+$/.test(quantity) ? BigInt(quantity) : 0n);
            row.querySelector('[data-subtotal]').textContent = money(subtotal);
            total += subtotal;
        });
        section.querySelector('[data-order-total]').textContent = money(total);
        section.querySelector('[data-add-item]').disabled = container.children.length >= 100;
        container.querySelectorAll('[data-remove-item]').forEach((button) => {
            button.disabled = container.children.length <= 1;
        });
    };

    section.querySelector('[data-add-item]').addEventListener('click', () => {
        if (container.children.length >= 100) return;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name], [id], [for], [aria-describedby]').forEach((element) => {
            ['name', 'id', 'for', 'aria-describedby'].forEach((attribute) => {
                if (element.hasAttribute(attribute)) {
                    element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(nextIndex)));
                }
            });
        });
        nextIndex += 1;
        container.append(fragment);
        recalculate();
        container.lastElementChild.querySelector('[data-product]').focus();
    });
    section.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-item]');
        if (button && container.children.length > 1) {
            button.closest('[data-order-row]').remove();
            recalculate();
        }
    });
    section.addEventListener('input', recalculate);
    section.addEventListener('change', recalculate);
    recalculate();
});
