const { ajax_url, nonce } = worrpb_php_data;

export const __request = async (url, options) => {
  const response = await fetch(url, options);
  return response.json();
};

export const getBackups = async () => {
  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action: 'worrpb_ajax_get_backups',
      nonce: nonce.worrpb_nonce,
    }),
  });
  return response;
};

export const doBackupProcess = async (process) => {
  const { action, payload } = process;

  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action,
      ...Object.fromEntries(Object.entries(payload).map(([key, value]) => [`payload[${key}]`, value])),
      nonce: nonce.worrpb_nonce,
    }),
  });

  return response;
}

/**
 * Delete backup folder
 * @param {string} name_folder
 * @returns {Promise<boolean>}
 */
export const deleteBackupFolder = async (name_folder) => {
  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action: 'worrpb_ajax_delete_backup_folder',
      ...Object.fromEntries(Object.entries({ name_folder }).map(([key, value]) => [`payload[${key}]`, value])),
      nonce: nonce.worrpb_nonce,
    }),
  });

  return response;
}

/**
 * Returns a human-friendly relative time string for a given date, using the provided server current datetime.
 * Examples: "just now", "1m ago", "5h ago", "yesterday", "3d ago", "2w ago", "Mar 5", "Mar 5, 2023"
 * @param {string|Date|number} inputDatetime - The date to format.
 * @param {string|Date|number} serverCurrentDatetime - The current datetime from the server.
 * @returns {string}
 */
export function friendlyDateTime(inputDatetime, serverCurrentDatetime) {
  const now = serverCurrentDatetime instanceof Date
    ? serverCurrentDatetime
    : new Date(serverCurrentDatetime);
  const date = inputDatetime instanceof Date
    ? inputDatetime
    : new Date(inputDatetime);

  if (isNaN(date.getTime()) || isNaN(now.getTime())) return "";

  const diffMs = now - date;
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);
  const diffWk = Math.floor(diffDay / 7);

  if (diffSec < 60) return "just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  if (diffHr < 24) return `${diffHr}h ago`;
  if (diffDay === 1) return "yesterday";
  if (diffDay < 7) return `${diffDay}d ago`;
  if (diffWk < 4) return `${diffWk}w ago`;

  // If this year, show "Mar 5"
  const options = { month: "short", day: "numeric" };
  if (date.getFullYear() === now.getFullYear()) {
    return date.toLocaleDateString(undefined, options);
  }
  // Else, show "Mar 5, 2023"
  return date.toLocaleDateString(undefined, { ...options, year: "numeric" });
}

export const doRestoreProcess = async (process) => {
  const { action, payload } = process;

  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action,
      ...Object.fromEntries(Object.entries(payload).map(([key, value]) => [`payload[${key}]`, value])),
      nonce: nonce.worrpb_nonce,
      wp_restore_nonce: nonce.wp_restore_nonce,
    }),
  });

  return response;
}

export const sendReportEmail = async (payload) => {
  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action: 'worrpb_ajax_send_report_email',
      ...Object.fromEntries(Object.entries(payload).map(([key, value]) => [`payload[${key}]`, value])),
      nonce: nonce.worrpb_nonce,
    }),
  });

  return response;
};

export const uploadFileWithProgress = (file, onProgress) => {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const formData = new FormData();

    formData.append('action', 'worrpb_ajax_upload_backup_file');
    formData.append('nonce', nonce.worrpb_nonce);
    formData.append('file', file);

    xhr.open('POST', ajax_url);

    // Handle upload progress
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        const percent = Math.round((event.loaded / event.total) * 100);
        onProgress(percent);
      }
    };

    xhr.onload = () => {
      if (xhr.status === 200) {
        const res = JSON.parse(xhr.responseText);
        if (res.success) resolve(res);
        else reject(res.data || 'Unknown error');
      } else {
        reject('Upload failed');
      }
    };

    xhr.onerror = () => reject('Upload error');
    xhr.send(formData);
  });
};

export const getBackupDownloadZipPath = async (folder_name) => {
  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action: 'worrpb_ajax_get_backup_download_zip_path',
      ...Object.fromEntries(Object.entries({ folder_name }).map(([key, value]) => [`payload[${key}]`, value])),
      nonce: nonce.worrpb_nonce,
    }),
  });

  return response;
};

export const createBackupZip = async (folder_name) => {
  const response = await __request(ajax_url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams({
      action: 'worrpb_ajax_create_backup_zip',
      ...Object.fromEntries(Object.entries({ folder_name }).map(([key, value]) => [`payload[${key}]`, value])),
      nonce: nonce.worrpb_nonce,
    }),
  });

  return response;
};

export const saveBackupScheduleConfig = async (payload) => {
  const endpoint = `${ajax_url}?action=worrpb_ajax_save_backup_schedule_config`;
  const response = await __request(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      payload,
      nonce: nonce.worrpb_nonce
    }),
  });

  return response;
}

export const getBackupScheduleConfig = async () => {
  const endpoint = `${ajax_url}?action=worrpb_ajax_get_backup_schedule_config`;
  const response = await __request(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      nonce: nonce.worrpb_nonce
    }),
  });

  return response;
}