(function () {
    'use strict';

    var OPEN_CLASS = 'fullmetrix-combo-open';

    function buildOption(option, index)
    {
        var item = document.createElement('li');

        item.className = 'fullmetrix-combo-option';
        item.setAttribute('role', 'option');
        item.setAttribute('data-index', String(index));
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        item.textContent = option.textContent.trim();

        return item;
    }

    function enhance(select)
    {
        if (select.getAttribute('data-fullmetrix-enhanced') === 'true') {
            return;
        }
        select.setAttribute('data-fullmetrix-enhanced', 'true');

        var options = Array.prototype.slice.call(select.options);
        var wrapper = document.createElement('div');
        var button = document.createElement('button');
        var value = document.createElement('span');
        var list = document.createElement('ul');
        var activeIndex = select.selectedIndex < 0 ? 0 : select.selectedIndex;

        wrapper.className = 'fullmetrix-combo';

        button.type = 'button';
        button.className = 'fullmetrix-combo-button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');

        value.className = 'fullmetrix-combo-value';
        value.textContent = options.length ? options[activeIndex].textContent.trim() : '';
        button.appendChild(value);

        list.className = 'fullmetrix-combo-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        options.forEach(function (option, index) {
            list.appendChild(buildOption(option, index));
        });

        select.classList.add('fullmetrix-combo-native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(button);
        wrapper.appendChild(list);
        wrapper.appendChild(select);

        var items = Array.prototype.slice.call(list.children);

        function paint()
        {
            items.forEach(function (item, index) {
                item.setAttribute('aria-selected', index === select.selectedIndex ? 'true' : 'false');
                item.classList.toggle('fullmetrix-combo-active', index === activeIndex);
            });
            value.textContent = options[select.selectedIndex].textContent.trim();
        }

        function isOpen()
        {
            return !list.hidden;
        }

        function open()
        {
            if (isOpen()) {
                return;
            }
            activeIndex = select.selectedIndex < 0 ? 0 : select.selectedIndex;
            list.hidden = false;
            wrapper.classList.add(OPEN_CLASS);
            button.setAttribute('aria-expanded', 'true');
            paint();
            if (items[activeIndex]) {
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function close(refocus)
        {
            if (!isOpen()) {
                return;
            }
            list.hidden = true;
            wrapper.classList.remove(OPEN_CLASS);
            button.setAttribute('aria-expanded', 'false');
            if (refocus) {
                button.focus();
            }
        }

        function choose(index)
        {
            if (index < 0 || index >= options.length) {
                return;
            }
            select.selectedIndex = index;
            activeIndex = index;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            paint();
            close(true);
        }

        function move(delta)
        {
            var next = activeIndex + delta;

            if (next < 0) {
                next = 0;
            }
            if (next > options.length - 1) {
                next = options.length - 1;
            }
            activeIndex = next;
            paint();
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        button.addEventListener('click', function () {
            if (isOpen()) {
                close(false);
            } else {
                open();
            }
        });

        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
                if (event.key === 'ArrowDown') {
                    move(1);
                }
                if (event.key === 'ArrowUp') {
                    move(-1);
                }
            }
        });

        list.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                move(-1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                activeIndex = 0;
                paint();
            } else if (event.key === 'End') {
                event.preventDefault();
                activeIndex = options.length - 1;
                paint();
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                choose(activeIndex);
            }
        });

        items.forEach(function (item, index) {
            item.addEventListener('click', function () {
                choose(index);
            });
            item.addEventListener('mousemove', function () {
                activeIndex = index;
                paint();
            });
        });

        wrapper.addEventListener('focusout', function (event) {
            if (!wrapper.contains(event.relatedTarget)) {
                close(false);
            }
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                close(false);
            }
        });

        list.setAttribute('tabindex', '-1');
        button.addEventListener('focus', function () {
            activeIndex = select.selectedIndex < 0 ? 0 : select.selectedIndex;
        });

        paint();
    }

    function init()
    {
        Array.prototype.forEach.call(
            document.querySelectorAll('select[data-fullmetrix-select]'),
            enhance
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
