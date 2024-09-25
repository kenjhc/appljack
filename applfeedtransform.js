const fs = require('fs');
const path = require('path');
const sax = require('sax');
const js2xmlparser = require("js2xmlparser");

const inputDirectory = 'feeddownloads';
const outputDirectory = 'feedsclean';

// Read all XML files from the input directory
fs.readdir(inputDirectory, (err, files) => {
  if (err) {
    console.error(`Error reading directory: ${inputDirectory}`, err);
    return;
  }

  // Filter out non-XML files
  const xmlFiles = files.filter(file => path.extname(file).toLowerCase() === '.xml');

  // Process each XML file
  xmlFiles.forEach(file => {
    const inputFilePath = path.join(inputDirectory, file);
    const outputFilePath = path.join(outputDirectory, file);

    console.log(`Processing file: ${inputFilePath}`);

    const inputStream = fs.createReadStream(inputFilePath);
    const outputStream = fs.createWriteStream(outputFilePath);

    const parser = sax.createStream(true, { trim: true, normalize: true });

    let currentElement = null;
    let currentJob = null;
    let currentText = '';
    let jobs = [];
    let nestedElementStack = [];
    let hasNestedElements = false;
    let firstJobChecked = false;
    let stopProcessing = false;
    let elementCount = 0;
    let jobElementFound = false;

    const handleOpenTag = (node) => {
      if (stopProcessing) return;

      currentElement = node.name;
      currentText = '';

      elementCount++;
      if (elementCount <= 5 && currentElement === 'job') {
        jobElementFound = true;
      }

      if (!jobElementFound && elementCount > 5) {
        // Skip the file if no <job> element is found within the first 5 elements
        fs.copyFileSync(inputFilePath, outputFilePath);
        console.log(`Skipping file (no <job> element found in the first 5 elements): ${inputFilePath}`);
        console.log(`Original XML has been saved to ${outputFilePath}`);
        stopProcessing = true;
        inputStream.close();
        outputStream.end();
        return;
      }

      if (currentElement === 'job') {
        if (!firstJobChecked) {
          currentJob = {};
          nestedElementStack = [];
          hasNestedElements = false; // Reset for each job
        } else {
          // Continue to process the file after the first job check
          currentJob = {};
          nestedElementStack = [];
        }
      } else if (currentJob !== null) {
        if (nestedElementStack.length > 0) {
          hasNestedElements = true;
        }
        nestedElementStack.push(currentElement);
      }
    };

    const handleText = (text) => {
      if (stopProcessing) return;

      if (currentElement && currentJob !== null) {
        currentText += text;
      }
    };

    const handleCloseTag = (node) => {
      if (stopProcessing) return;

      if (node === 'job') {
        if (!firstJobChecked) {
          firstJobChecked = true;
          if (hasNestedElements) {
            // Flatten the nested elements
            let flattenedJob = {};
            Object.keys(currentJob).forEach(key => {
              if (typeof currentJob[key] === 'object' && currentJob[key] !== null) {
                Object.keys(currentJob[key]).forEach(subKey => {
                  flattenedJob[`${key}${subKey}`] = currentJob[key][subKey];
                });
              } else {
                flattenedJob[key] = currentJob[key];
              }
            });
            jobs.push(flattenedJob);
          } else {
            fs.copyFileSync(inputFilePath, outputFilePath);
            console.log(`Skipping file (no nested elements): ${inputFilePath}`);
            console.log(`Original XML has been saved to ${outputFilePath}`);
            stopProcessing = true;
            inputStream.close();
            outputStream.end();
            return;
          }
        } else {
          jobs.push(currentJob);
        }
      } else if (currentJob !== null) {
        if (nestedElementStack.length > 1) {
          const parentElement = nestedElementStack[nestedElementStack.length - 2];
          if (!currentJob[parentElement]) {
            currentJob[parentElement] = {};
          }
          currentJob[parentElement][currentElement] = currentText.trim();
        } else {
          currentJob[currentElement] = currentText.trim();
        }
        nestedElementStack.pop();
        currentElement = null;
      }
    };

    const handleCDATA = (cdata) => {
      if (stopProcessing) return;

      if (currentElement && currentJob !== null) {
        currentText += cdata;
      }
    };

    const handleEnd = () => {
      if (!stopProcessing && jobs.length > 0) {
        const structuredData = {
          source: {
            jobs: {
              job: jobs.map(job => {
                let flattenedJob = {};
                Object.keys(job).forEach(key => {
                  if (typeof job[key] === 'object' && job[key] !== null) {
                    Object.keys(job[key]).forEach(subKey => {
                      flattenedJob[`${key}${subKey}`] = job[key][subKey];
                    });
                  } else {
                    flattenedJob[key] = job[key];
                  }
                });
                return flattenedJob;
              })
            }
          }
        };
        const xml = js2xmlparser.parse("source", structuredData.source);
        outputStream.write(xml);
        outputStream.end();
        console.log(`Transformed XML with nested elements flattened has been saved to ${outputFilePath}`);
      }
    };

    const handleError = (err) => {
      console.error("Parsing error:", err);
    };

    parser.on('opentag', handleOpenTag);
    parser.on('text', handleText);
    parser.on('cdata', handleCDATA);
    parser.on('closetag', handleCloseTag);
    parser.on('end', handleEnd);
    parser.on('error', handleError);

    inputStream.pipe(parser);
  });
});
