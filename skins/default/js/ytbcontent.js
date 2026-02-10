(function () {
    function initSlider(section) {
        var list = section.querySelector('.ytbcontent-list');
        var dots = section.querySelectorAll('.ytbcontent-dot');
        var ellipsisStart = section.querySelector('.ytbcontent-dots-ellipsis.is-start');
        var ellipsisEnd = section.querySelector('.ytbcontent-dots-ellipsis.is-end');
        if (!list || dots.length === 0) {
            return;
        }

        function updateDotGroup(index) {
            var maxVisible = 7;
            var total = dots.length;
            var start = 0;
            var end = total - 1;

            if (total > maxVisible) {
                var half = Math.floor(maxVisible / 2);
                start = index - half;
                if (start < 0) {
                    start = 0;
                }
                if (start > total - maxVisible) {
                    start = total - maxVisible;
                }
                end = start + maxVisible - 1;
            }

            dots.forEach(function (dot, i) {
                if (i < start || i > end) {
                    dot.classList.add('is-hidden');
                } else {
                    dot.classList.remove('is-hidden');
                }
            });

            if (ellipsisStart) {
                ellipsisStart.classList.toggle('is-hidden', start === 0);
            }
            if (ellipsisEnd) {
                ellipsisEnd.classList.toggle('is-hidden', end >= total - 1);
            }
        }

        function setActiveDot(index) {
            dots.forEach(function (dot, i) {
                if (i === index) {
                    dot.classList.add('is-active');
                } else {
                    dot.classList.remove('is-active');
                }
            });
            updateDotGroup(index);
        }

        function scrollToIndex(index) {
            var items = list.children;
            if (!items[index]) {
                return;
            }
            if (typeof items[index].scrollIntoView === 'function') {
                items[index].scrollIntoView({
                    behavior: 'smooth',
                    inline: 'start',
                    block: 'nearest'
                });
            } else {
                list.scrollTo({
                    left: items[index].offsetLeft,
                    behavior: 'smooth'
                });
            }
        }

        dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                scrollToIndex(index);
                setActiveDot(index);
            });
        });

        var ticking = false;
        list.addEventListener('scroll', function () {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                var items = list.children;
                var listRect = list.getBoundingClientRect();
                var closestIndex = 0;
                var closestDistance = Infinity;

                for (var i = 0; i < items.length; i += 1) {
                    var itemRect = items[i].getBoundingClientRect();
                    var distance = Math.abs(itemRect.left - listRect.left);
                    if (distance < closestDistance) {
                        closestDistance = distance;
                        closestIndex = i;
                    }
                }

                setActiveDot(closestIndex);
                ticking = false;
            });
        });

        setActiveDot(0);
    }

    function applyResponsiveVars(section, width) {
        var lines = 3;
        if (width >= 480) {
            lines = 5;
        } else if (width >= 300) {
            lines = 4;
        }
        section.style.setProperty('--ytb-desc-lines', String(lines));

        var titleSize = 1.2;
        if (width >= 480) {
            titleSize = 2.0;
        } else if (width >= 300) {
            titleSize = 1.6;
        }
        section.style.setProperty('--ytb-item-title-size', titleSize + 'rem');
    }

    function initDescLines(section) {
        var update = function () {
            applyResponsiveVars(section, section.getBoundingClientRect().width);
        };

        if (typeof ResizeObserver !== 'undefined') {
            var observer = new ResizeObserver(function (entries) {
                entries.forEach(function (entry) {
                    applyResponsiveVars(section, entry.contentRect.width);
                });
            });
            observer.observe(section);
        } else {
            window.addEventListener('resize', update);
            update();
        }
    }

    function initAll() {
        var sections = document.querySelectorAll('.ytbcontent-default');
        sections.forEach(function (section) {
            initSlider(section);
            initDescLines(section);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
