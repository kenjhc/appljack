const axios = require("axios");
const fs = require("fs");
const csvParse = require("csv-parse/sync");
const js2xmlparser = require("js2xmlparser");
const { envSuffix } = require("./config");

// URL of the CSV file you want to download
const csvFileUrl =
  "https://docs.google.com/spreadsheets/d/e/2PACX-1vR3V2IkGlSzc-S1jWLKLqJ7fgALYua8C0dTJO13jayFYhUHEQ2P776aC2HV7H1B53-Kbf-WfZ7_2GX_/pub?gid=0&single=true&output=csv";

async function downloadCsvAndConvertToXml() {
  try {
    const response = await axios.get(csvFileUrl, {
      responseType: "arraybuffer",
    });

    if (response.status === 200) {
      const csvData = response.data.toString("utf8");

      // Parse the CSV data
      let records = csvParse.parse(csvData, {
        columns: true,
        skip_empty_lines: true,
      });

      // Add custid to each record
      records = records.map((record) => ({
        ...record,
        custid: "4493813871", // Add the custid field to each record
      }));

      // New structure for XML conversion
      const jobsData = {
        job: records, // Wrap the records in a job array
      };

      // Convert the structured data to XML
      const xml = js2xmlparser.parse("jobs", jobsData);

      const xmlFilePath = `/chroot/home/appljack/appljack.com/html${envSuffix}/feeddownloads/xmldl_toothio.xml`;
      fs.writeFileSync(xmlFilePath, xml);

      console.log(`XML file saved to ${xmlFilePath}`);
    } else {
      console.error("Failed to download the CSV file");
    }
  } catch (error) {
    console.error("Error:", error.message);
  }
}

// Call the function to start the download and conversion process
downloadCsvAndConvertToXml();
