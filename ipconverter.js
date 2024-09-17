const fs = require('fs');
const csv = require('csv-parser');
const { EOL } = require('os');

function no2ip(iplong) {
  return (
    ((iplong >> 24) & 255) +
    '.' +
    ((iplong >> 16) & 255) +
    '.' +
    ((iplong >> 8) & 255) +
    '.' +
    (iplong & 255)
  );
}

function processCsv(inputFilePath, outputFilePath) {
  const outputStream = fs.createWriteStream(outputFilePath);
  const batchSize = 10000; // Adjust batch size based on available memory
  let batch = [];

  fs.createReadStream(inputFilePath)
    .pipe(csv({ headers: false }))
    .on('data', (row) => {
      const ipStart = parseInt(row[0], 10);
      const ipEnd = parseInt(row[1], 10);
      const countryCode = row[2];
      const countyName = row[3];
      const region = row[4];
      const city = row[5];

      const ipStartAddress = no2ip(ipStart);
      const ipEndAddress = no2ip(ipEnd);

      const newRow = [
        ipStart,
        ipEnd,
        countryCode,
        countyName,
        region,
        city,
        ipStartAddress,
        ipEndAddress,
      ].join(',') + EOL;

      batch.push(newRow);

      if (batch.length >= batchSize) {
        outputStream.write(batch.join(''));
        batch = [];
      }
    })
    .on('end', () => {
      if (batch.length > 0) {
        outputStream.write(batch.join(''));
      }
      outputStream.end(() => {
        console.log('CSV file processing complete.');
      });
    })
    .on('error', (error) => {
      console.error('An error occurred while processing the CSV file:', error);
    });
}

const inputFilePath = 'IP2LOCATION-LITE-DB3.CSV'; // Replace with your input CSV file path
const outputFilePath = 'ipaddressconverted.csv'; // Replace with your output CSV file path

processCsv(inputFilePath, outputFilePath);
