/** A zip assembled byte by byte, independently of the extractor's own writer, for container fixtures. */
import { deflateRawSync, crc32 } from 'node:zlib';

/** members: [{name, data}]; a trailing slash in the name makes a directory. Deflates members when asked. */
export function buildZip(members, deflate = false) {
	const parts = [],
		central = [];
	let offset = 0;
	for (const { name, data } of members) {
		const nameBytes = Buffer.from(name);
		const packed = deflate && !name.endsWith('/') ? deflateRawSync(data) : data;
		const method = packed === data ? 0 : 8;
		const header = Buffer.alloc(30);
		header.writeUInt32LE(0x04034b50, 0);
		header.writeUInt16LE(20, 4);
		header.writeUInt16LE(0x800, 6);
		header.writeUInt16LE(method, 8);
		header.writeUInt32LE(crc32(data), 14);
		header.writeUInt32LE(packed.length, 18);
		header.writeUInt32LE(data.length, 22);
		header.writeUInt16LE(nameBytes.length, 26);
		const record = Buffer.alloc(46);
		record.writeUInt32LE(0x02014b50, 0);
		record.writeUInt16LE(20, 4);
		record.writeUInt16LE(20, 6);
		record.writeUInt16LE(0x800, 8);
		record.writeUInt16LE(method, 10);
		record.writeUInt32LE(crc32(data), 16);
		record.writeUInt32LE(packed.length, 20);
		record.writeUInt32LE(data.length, 24);
		record.writeUInt16LE(nameBytes.length, 28);
		record.writeUInt32LE(offset, 42);
		central.push(Buffer.concat([record, nameBytes]));
		parts.push(header, nameBytes, packed);
		offset += 30 + nameBytes.length + packed.length;
	}
	const directoryBytes = Buffer.concat(central);
	const end = Buffer.alloc(22);
	end.writeUInt32LE(0x06054b50, 0);
	end.writeUInt16LE(members.length, 8);
	end.writeUInt16LE(members.length, 10);
	end.writeUInt32LE(directoryBytes.length, 12);
	end.writeUInt32LE(offset, 16);
	return Buffer.concat([...parts, directoryBytes, end]);
}
