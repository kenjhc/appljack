const fs = require('fs');
const sax = require('sax');

// Path to the XML file
const xmlFilePath = '/chroot/home/appljack/appljack.com/html/applfeeds/7914340915-b1a0971369.xml';

// Create a readable stream for the XML file
const stream = fs.createReadStream(xmlFilePath);

// Create a SAX parser
const parser = sax.createStream(true, { trim: true });

// Initialize a counter
let jobCount = 0;

// Set up the handler for when a <job> entity is encountered
parser.on('opentag', (node) => {
  if (node.name === 'job') {
    jobCount++;
  }
});

// Handle the end of the file
parser.on('end', () => {
  console.log(`Total <job> entities: ${jobCount}`);
});

// Handle any errors
parser.on('error', (err) => {
  console.error('Error:', err);
});

// Pipe the stream to the parser
stream.pipe(parser);
