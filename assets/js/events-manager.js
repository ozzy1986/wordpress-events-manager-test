(() => {
  const config = window.EventsManagerConfig;

  if (!config) {
    return;
  }

  const wrappers = document.querySelectorAll('.em-events');

  if (!wrappers.length) {
    return;
  }

  const setStatus = (element, message = '') => {
    if (!element) {
      return;
    }

    element.textContent = message;
    element.hidden = message === '';
  };

  wrappers.forEach((wrapper) => {
    const button = wrapper.querySelector('.em-events__more');
    const items = wrapper.querySelector('.em-events__items');
    const status = wrapper.querySelector('.em-events__status');

    if (!button || !items) {
      return;
    }

    const defaultLabel = button.textContent.trim();

    button.addEventListener('click', async () => {
      if (button.disabled) {
        return;
      }

      const nextPage = Number(wrapper.dataset.nextPage || '2');
      const body = new URLSearchParams();

      body.append('action', config.action);
      body.append('nonce', config.nonce);
      body.append('page', String(nextPage));

      button.disabled = true;
      button.textContent = 'Loading...';
      wrapper.classList.add('is-loading');
      setStatus(status);

      try {
        const response = await fetch(config.ajaxUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          },
          body: body.toString(),
        });

        if (!response.ok) {
          throw new Error('Network error');
        }

        const result = await response.json();

        if (!result || !result.success) {
          throw new Error('Invalid response');
        }

        if (result.data.html) {
          items.insertAdjacentHTML('beforeend', result.data.html);
          wrapper.dataset.nextPage = String(result.data.nextPage);
        }

        if (!result.data.hasMore || result.data.isEmpty) {
          button.hidden = true;
        }
      } catch (error) {
        setStatus(status, 'Could not load more events.');
      } finally {
        button.disabled = false;
        button.textContent = defaultLabel;
        wrapper.classList.remove('is-loading');
      }
    });
  });
})();
