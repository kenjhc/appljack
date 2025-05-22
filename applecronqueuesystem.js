function queryPromise(pool, sql, params = []) {
  return new Promise((resolve, reject) => {
    pool.query(sql, params, (err, results) => {
      if (err) return reject(err);
      resolve(results);
    });
  });
}

async function createApplCronQueuesTable(pool) {
  try {
    console.log('createApplCronQueuesTable---start');

    const result = await queryPromise(pool, `SHOW TABLES LIKE 'applcronqueues';`);

    if (result.length === 0) {
      console.log('Table does not exist. Creating now...');

      const createTableQuery = `
        CREATE TABLE applcronqueues (
          id INT AUTO_INCREMENT PRIMARY KEY,
          type VARCHAR(255),
          data TEXT,
          log TEXT NULL,
          process INT,
          status ENUM('0', '1', '2', '3') NOT NULL
        );
      `;

      await queryPromise(pool, createTableQuery);
      console.log('Table "applcronqueues" created successfully.');
    } else {
      console.log('Table "applcronqueues" already exists.');
    }

    console.log('createApplCronQueuesTable---end');
  } catch (err) {
    console.error('Error in createApplCronQueuesTable:', err);
  }
}

async function latestProcess(pool, type) {
  return new Promise((resolve, reject) => {
    try {
      let process = 0;

      const query = `
        SELECT process FROM applcronqueues
        WHERE type = "${type}"
        ORDER BY id DESC
        LIMIT 1;
      `;

      pool.query(query, async (err, results) => {
        if (err) {
          console.error("Error fetching latest process:", err);
          return;
        }

        if (results.length > 0) {
          process = await results[0].process;
        } else {
          console.log("No records found.");
        }

        resolve(process+1);
      });
    } catch (error) {
      reject(error);
    }
  });
}

async function insertNonExistingFiles(pool, files) {
  try {
    if (!Array.isArray(files) || files.length === 0) {
      console.log("No files provided.");
      return;
    }

    const placeholders = files.map(() => '?').join(',');
    const selectQuery = `
      SELECT data FROM applcronqueues
      WHERE type = 'applcountjobs' AND (status = '0' OR status = '1') AND data IN (${placeholders})
    `;

    const result = await queryPromise(pool, selectQuery, files);
    const existingData = result.map(row => row.data);

    let filesToInsert = [];

    if (existingData.length === 0) {
      console.log("No existing records. Inserting all files.");
      filesToInsert = files;
    } else {
      filesToInsert = files.filter(file => !existingData.includes(file));
    }

    if (filesToInsert.length === 0) {
      console.log("All files already exist. No insert needed.");
      return;
    }

    const insertValues = filesToInsert
      .filter(file => file.match(/^(\d+)-([a-zA-Z0-9]+)\.xml$/)) // Validate pattern
      .map(file => ['applcountjobs', file, '0']);

    if (insertValues.length === 0) {
      console.warn("No valid files to insert.");
      return;
    }

    const insertQuery = `
      INSERT INTO applcronqueues (type, data, status)
      VALUES ?
    `;

    const insertResult = await queryPromise(pool, insertQuery, [insertValues]);
    console.log(`Inserted ${insertResult.affectedRows} file(s) successfully.`);
  } catch (error) {
    console.error("Error in insertNonExistingFiles:", error);
  }
}

async function getSingleFile(pool) {
  const query = `
    SELECT * FROM applcronqueues
    WHERE status = '0'
    LIMIT 1;
  `;

  return new Promise((resolve, reject) => {
    pool.query(query, (err, results) => {
      if (err) return reject(err);

      if (results.length > 0) {
        resolve(results[0]);
      }

        resolve(null);
    });
  });
}

async function cronQueue(pool, files) {
  try {
    await createApplCronQueuesTable(pool);
    await insertNonExistingFiles(pool, files);
  } catch (error) {
    process.exit(0);
  }
}

function cronQueueLog(pool, id, updatedData) {
  return new Promise(async (resolve, reject) => {
    try {
      if (!id || typeof updatedData !== 'object' || Object.keys(updatedData).length === 0) {
        throw new Error('Invalid parameters: id and updatedData are required');
      }

      const setClauses = [];
      const values = [];

      for (const [col, val] of Object.entries(updatedData)) {
        setClauses.push(`\`${col}\` = ?`);
        values.push(val);
      }

      values.push(id);

      const updateQuery = `
        UPDATE \`applcronqueues\`
        SET ${setClauses.join(', ')}
        WHERE id = ?
      `;

      await queryPromise(pool, updateQuery, values);

      console.log(`Row with id=${id} updated in applcronqueues`);
      resolve(true);
    } catch (error) {
      console.error('Error in cronQueueLog:', error);
      reject(error);
    }
  });
}

module.exports = { 
  createApplCronQueuesTable, 
  latestProcess,
  insertNonExistingFiles,
  getSingleFile,
  cronQueue,
  cronQueueLog
};
